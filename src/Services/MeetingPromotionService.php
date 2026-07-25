<?php

namespace Platform\Meetings\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\User;
use Platform\Meetings\Models\Meeting;
use Platform\Meetings\Models\MeetingParticipant;
use Platform\Meetings\Models\MeetingSeries;

/**
 * Promotet ein Meeting-Inbox-Item zu einer echten Meeting-Instanz (Workspace).
 *
 * Verzweigt nach Termin-Art:
 *   - Einzeltermin (occurrence_type singleInstance/null) → EINE Standalone-Instanz.
 *   - Serie (occurrence/exception/seriesMaster oder series_master_id) → eine
 *     MeetingSeries (find-or-create per iCalUId) + je Vorkommen ein Meeting darunter.
 *
 * Identität = iCalUId (über Beteiligte UND Vorkommen stabil), Fallback series_master_id.
 * Teilnehmer werden per E-Mail → User gematcht (Mitgliedschaft: Beteiligte sehen die
 * geteilte Instanz). Die Instanz hängt an denselben Org-Knoten wie das Inbox-Item und
 * trägt dort Wissen bei — Zeit läuft getrennt über die Inbox-Items (keine Doppelzählung).
 *
 * Loose gekoppelt: liest Inbox-/Connector-Tabellen via DB::table, nutzt die
 * Organization-Bridge nur, wenn vorhanden.
 */
class MeetingPromotionService
{
    public function promoteInboxItem(int $inboxItemId): ?Meeting
    {
        if (! Schema::hasTable('inbox_items')) {
            return null;
        }

        $item = DB::table('inbox_items')->where('id', $inboxItemId)->first();
        if (! $item || $item->channel !== 'meeting') {
            return null;
        }

        $session = $this->loadSession($item);
        $icalUid = $item->ical_uid ?? null;
        $seriesMasterId = $item->series_master_id ?? null;
        $occurrence = $item->occurrence_type ?? null;

        $isSeries = in_array($occurrence, ['occurrence', 'exception', 'seriesMaster'], true)
            || ! empty($seriesMasterId);

        return $isSeries
            ? $this->promoteSeries($item, $session, $icalUid, $seriesMasterId)
            : $this->promoteSingle($item, $session, $icalUid, $seriesMasterId);
    }

    /**
     * Einzeltermin → eine Standalone-Instanz (find-or-create per iCalUId).
     */
    protected function promoteSingle($item, $session, ?string $icalUid, ?string $seriesMasterId): Meeting
    {
        $meeting = null;
        if ($icalUid && Schema::hasColumn('meetings_meetings', 'ical_uid')) {
            $meeting = Meeting::whereNull('meeting_series_id')->where('ical_uid', $icalUid)->first();
        }
        if (! $meeting) {
            $meeting = $this->makeMeeting($item, $session, $icalUid, $seriesMasterId, null);
        }

        $this->backlinkItem($item, $meeting);
        // Weitere offene Vorkommen gleicher Identität (falls doch vorhanden) mitbinden.
        $this->backlinkSameIdentity($item, $meeting, $icalUid, $seriesMasterId);

        return $meeting;
    }

    /**
     * Serie → MeetingSeries (find-or-create per iCalUId) + je Vorkommen ein Meeting.
     * Klinkt die GANZE Serie ein: alle offenen Vorkommen des Nutzers docken an.
     */
    protected function promoteSeries($item, $session, ?string $icalUid, ?string $seriesMasterId): ?Meeting
    {
        $series = $this->findOrCreateSeries($item, $session, $icalUid);

        $identityCol = $icalUid ? 'ical_uid' : ($seriesMasterId ? 'series_master_id' : null);
        $identityVal = $icalUid ?: $seriesMasterId;

        $occurrences = $identityCol
            ? DB::table('inbox_items')->where('user_id', $item->user_id)->where($identityCol, $identityVal)->get()
            : collect([$item]);

        $promoted = null;
        foreach ($occurrences as $occ) {
            $occSession = $this->loadSession($occ);
            $start = $occSession->start_at ?? $occ->received_at ?? null;

            $meeting = Meeting::where('meeting_series_id', $series->id)
                ->when($start, fn ($q) => $q->whereDate('start_date', Carbon::parse($start)->toDateString()))
                ->first();

            if (! $meeting) {
                $meeting = $this->makeMeeting($occ, $occSession, $icalUid, $seriesMasterId, $series->id);
            }

            DB::table('inbox_items')->where('id', $occ->id)->update(['meeting_id' => $meeting->id]);

            if ((int) $occ->id === (int) $item->id) {
                $promoted = $meeting;
            }
        }

        return $promoted ?? Meeting::where('meeting_series_id', $series->id)->first();
    }

    protected function findOrCreateSeries($item, $session, ?string $icalUid): MeetingSeries
    {
        if ($icalUid && Schema::hasColumn('meetings_series', 'ical_uid')) {
            $existing = MeetingSeries::where('ical_uid', $icalUid)->first();
            if ($existing) {
                return $existing;
            }
        }

        $start = $session->start_at ?? $item->received_at ?? null;
        $end = $session->end_at ?? null;

        $series = new MeetingSeries();
        $series->team_id = $item->team_id;
        $series->user_id = $item->user_id;
        if (Schema::hasColumn('meetings_series', 'ical_uid')) {
            $series->ical_uid = $icalUid;
        }
        $series->title = $session->subject ?? $item->subject ?? 'Meeting-Serie';
        $series->description = $session->body_preview ?? null;
        $series->location = $session->location ?? null;
        $series->start_time = $start ? Carbon::parse($start)->format('H:i:s') : null;
        $series->end_time = $end ? Carbon::parse($end)->format('H:i:s') : null;
        $series->recurrence_type = null; // aus dem Kalender importiert — kein eigener Generator
        $series->is_active = true;
        $series->next_meeting_date = $start ? Carbon::parse($start) : null;
        $series->save();

        return $series;
    }

    protected function makeMeeting($item, $session, ?string $icalUid, ?string $seriesMasterId, ?int $seriesId): Meeting
    {
        $meeting = new Meeting();
        $meeting->team_id = $item->team_id;
        $meeting->user_id = $item->user_id;
        $meeting->meeting_series_id = $seriesId;
        $meeting->series_master_id = $seriesMasterId;
        if (Schema::hasColumn('meetings_meetings', 'ical_uid')) {
            $meeting->ical_uid = $icalUid;
        }
        $meeting->title = $session->subject ?? $item->subject ?? 'Meeting';
        $meeting->description = $session->body_preview ?? null;
        $meeting->location = $session->location ?? null;
        $meeting->status = 'confirmed';
        $meeting->start_date = $session->start_at ?? $item->received_at ?? null;
        $meeting->end_date = $session->end_at ?? null;
        $meeting->save();

        $this->syncParticipants($meeting, $item, $session);
        $this->linkMeetingToItemEntities($meeting, (int) $item->id);

        return $meeting;
    }

    /**
     * Teilnehmer aus der Session (Organizer + Adressliste) anlegen und per E-Mail
     * an Platform-User matchen. Ein Teilnehmer mit user_id sieht die geteilte Instanz.
     */
    protected function syncParticipants(Meeting $meeting, $item, $session): void
    {
        if (! Schema::hasTable('meetings_participants') || ! $session) {
            return;
        }

        $organizer = $session->organizer_address ? strtolower(trim($session->organizer_address)) : null;

        $emails = [];
        if (! empty($session->attendee_addresses)) {
            foreach (preg_split('/[,;]/', (string) $session->attendee_addresses) as $addr) {
                $addr = strtolower(trim($addr));
                if ($addr !== '') {
                    $emails[] = $addr;
                }
            }
        }
        if ($organizer) {
            $emails[] = $organizer;
        }
        $emails = array_values(array_unique(array_filter($emails)));
        if (empty($emails)) {
            return;
        }

        $users = User::whereIn('email', $emails)->get()->keyBy(fn ($u) => strtolower($u->email));

        foreach ($emails as $email) {
            $user = $users->get($email);
            MeetingParticipant::updateOrCreate(
                ['meeting_id' => $meeting->id, 'email' => $email],
                [
                    'user_id' => $user?->id,
                    'name' => $user?->fullname ?? $user?->name ?? null,
                    'role' => ($organizer && $organizer === $email) ? 'organizer' : 'participant',
                ],
            );
        }
    }

    protected function loadSession($item)
    {
        if (
            ($item->source_type ?? null) === 'user_connector_meeting_session'
            && Schema::hasTable('user_connector_meeting_sessions')
        ) {
            return DB::table('user_connector_meeting_sessions')->where('id', $item->source_id)->first();
        }

        return null;
    }

    protected function backlinkItem($item, Meeting $meeting): void
    {
        DB::table('inbox_items')->where('id', $item->id)->update(['meeting_id' => $meeting->id]);
    }

    protected function backlinkSameIdentity($item, Meeting $meeting, ?string $icalUid, ?string $seriesMasterId): void
    {
        $identityCol = $icalUid ? 'ical_uid' : ($seriesMasterId ? 'series_master_id' : null);
        $identityVal = $icalUid ?: $seriesMasterId;
        if (! $identityCol) {
            return;
        }

        DB::table('inbox_items')
            ->where('user_id', $item->user_id)
            ->where($identityCol, $identityVal)
            ->whereNull('meeting_id')
            ->update(['meeting_id' => $meeting->id]);
    }

    /**
     * Hängt die Meeting-Instanz an dieselben Knoten wie das Inbox-Item.
     */
    protected function linkMeetingToItemEntities(Meeting $meeting, int $inboxItemId): void
    {
        $bridge = \Platform\Organization\Services\EntityDimensionBridge::class;
        if (! class_exists($bridge) || ! Schema::hasTable('organization_dimension_links')) {
            return;
        }

        $entityIds = $bridge::linksForLinkables(['inbox_item'], [$inboxItemId], false)
            ->pluck('entity_id')
            ->filter()
            ->unique()
            ->all();
        if (empty($entityIds)) {
            return;
        }

        $existing = $bridge::linksForLinkables(['meeting'], [$meeting->id], false)
            ->pluck('entity_id')
            ->filter()
            ->unique()
            ->flip();

        foreach ($entityIds as $entityId) {
            if (isset($existing[$entityId])) {
                continue;
            }
            $bridge::createLink((int) $entityId, 'meeting', $meeting->id);
        }
    }
}
