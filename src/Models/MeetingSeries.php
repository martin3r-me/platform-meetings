<?php

namespace Platform\Meetings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;
use Illuminate\Support\Facades\Auth;
use Platform\ActivityLog\Traits\LogsActivity;

class MeetingSeries extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'meetings_series';

    protected $fillable = [
        'uuid',
        'ical_uid',
        'user_id',
        'team_id',
        'title',
        'description',
        'location',
        'start_time',
        'end_time',
        'recurrence_type',
        'recurrence_day_of_week',
        'recurrence_day_of_month',
        'is_active',
        'next_meeting_date',
        'recurrence_end_date',
    ];

    protected $casts = [
        'recurrence_end_date' => 'date',
        'next_meeting_date' => 'datetime',
        'is_active' => 'boolean',
        'recurrence_day_of_week' => 'integer',
        'recurrence_day_of_month' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $uuid;

            if (! $model->user_id) {
                $model->user_id = Auth::id();
            }

            if (! $model->team_id) {
                $model->team_id = Auth::user()->currentTeam->id ?? null;
            }

            if ($model->is_active === null) {
                $model->is_active = true;
            }
        });

        // Cascade: löscht man eine Serie, verschwinden ihre Vorkommen mit (soft) —
        // sonst bleiben Waisen-Meetings im Dashboard hängen.
        static::deleting(function (self $model) {
            $model->meetings()->get()->each->delete();
        });
    }

    public function user()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class);
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class, 'meeting_series_id');
    }

    /**
     * Erstellt ein Meeting basierend auf diesem Serientermin
     */
    public function createMeeting(): Meeting
    {
        $startDate = $this->next_meeting_date->copy();
        $startDate->setTimeFromTimeString($this->start_time);

        $endDate = $this->next_meeting_date->copy();
        $endDate->setTimeFromTimeString($this->end_time);

        $meeting = Meeting::create([
            'user_id' => $this->user_id,
            'team_id' => $this->team_id,
            'meeting_series_id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'status' => 'planned',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        // Organizer als Participant hinzufügen
        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $this->user_id,
            'role' => 'organizer',
        ]);

        $this->calculateNextMeetingDate();
        $this->save();

        return $meeting;
    }

    /**
     * Berechnet das nächste Meeting-Datum basierend auf dem Wiederholungsmuster
     */
    public function calculateNextMeetingDate(): void
    {
        if (!$this->next_meeting_date) {
            $this->next_meeting_date = now();
        }

        $current = $this->next_meeting_date;

        $this->next_meeting_date = match($this->recurrence_type) {
            'weekly' => $current->copy()->addWeek(),
            'biweekly' => $current->copy()->addWeeks(2),
            'monthly' => $current->copy()->addMonth(),
            'quarterly' => $current->copy()->addMonths(3),
            'yearly' => $current->copy()->addYear(),
            default => $current->copy()->addWeek(),
        };
    }

    /**
     * Prüft, ob ein neues Meeting erstellt werden sollte
     */
    public function shouldCreateMeeting(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->recurrence_end_date && now()->isAfter($this->recurrence_end_date)) {
            return false;
        }

        if (!$this->next_meeting_date) {
            return false;
        }

        return now()->isSameDay($this->next_meeting_date) || now()->isAfter($this->next_meeting_date);
    }

    /**
     * Erstellt alle fehlenden Meetings bis zu einem bestimmten Datum
     */
    public function createMeetingsUntil(\Carbon\Carbon $untilDate): array
    {
        if (!$this->is_active) {
            return [];
        }

        $createdMeetings = [];
        $currentDate = $this->next_meeting_date ? $this->next_meeting_date->copy()->startOfDay() : now()->startOfDay();
        $untilDate = $untilDate->copy()->endOfDay();

        $existingDates = $this->meetings()
            ->where('start_date', '>=', $currentDate)
            ->where('start_date', '<=', $untilDate)
            ->get()
            ->map(fn($meeting) => $meeting->start_date->format('Y-m-d'))
            ->unique()
            ->toArray();

        $maxIterations = 1000;
        $iteration = 0;

        while ($currentDate->lte($untilDate) && $iteration < $maxIterations) {
            $iteration++;

            if ($this->recurrence_end_date && $currentDate->isAfter($this->recurrence_end_date)) {
                break;
            }

            $dateKey = $currentDate->format('Y-m-d');
            if (!in_array($dateKey, $existingDates)) {
                $this->next_meeting_date = $currentDate->copy();

                $meeting = $this->createMeeting();
                $createdMeetings[] = $meeting;

                $currentDate = $this->next_meeting_date->copy()->startOfDay();
            } else {
                $tempDate = $currentDate->copy();
                $this->next_meeting_date = $tempDate;
                $this->calculateNextMeetingDate();
                $currentDate = $this->next_meeting_date->copy()->startOfDay();
            }
        }

        $this->save();

        return $createdMeetings;
    }

    /**
     * Gibt das Recurrence Pattern als lesbaren Text zurück
     */
    public function getRecurrencePatternText(): ?string
    {
        if (!$this->recurrence_type) {
            return null;
        }

        $typeLabels = [
            'weekly' => 'Wöchentlich',
            'biweekly' => 'Alle 2 Wochen',
            'monthly' => 'Monatlich',
            'quarterly' => 'Vierteljährlich',
            'yearly' => 'Jährlich',
        ];

        return $typeLabels[$this->recurrence_type] ?? ucfirst($this->recurrence_type);
    }
}
