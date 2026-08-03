<?php

namespace Platform\Meetings\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Meetings\Models\Meeting;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Organization\Contracts\HasMetricDefinitions;
use Platform\Organization\Services\EntityLinkRegistry;

/**
 * Am Knoten verlinkte Meeting-Instanzen (morph `meeting`).
 *
 * Die Instanz steuert WISSEN zum Puls bei (Anzahl Meetings, Agenda-Punkte,
 * Notizen, Tage seit letztem Termin) UND die Ist-Zeit: pro Teilnehmer bucht
 * MaterializeMeetingTimeCommand eine OrganizationTimeEntry mit
 * context_type = Meeting::class auf DAS Meeting (nicht mehr aufs Inbox-Item).
 * Der EntityTimeResolver rollt diese Zeit über den `meeting`-Link auf den Knoten
 * auf; weil jeder Teilnehmer eine eigene Buchung auf dasselbe Meeting hat,
 * summieren sich die Personenstunden am gemeinsamen Knoten.
 */
class MeetingsEntityLinkProvider implements EntityLinkProvider, HasMetricDefinitions
{
    public function morphAliases(): array
    {
        return ['meeting'];
    }

    public function linkTypeConfig(): array
    {
        return [
            'meeting' => [
                'label' => 'Meetings',
                'singular' => 'Meeting',
                'icon' => 'heroicon-o-users',
                'route' => null,
            ],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        // no-op
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        return [];
    }

    public function metadataDisplayRules(): array
    {
        return [];
    }

    /**
     * Das Meeting SELBST trägt die Ist-Zeit (context_type = Meeting::class).
     * Keine Child-Relations — die Buchungen hängen direkt am Meeting.
     */
    public function timeTrackableCascades(): array
    {
        return [
            'meeting' => [Meeting::class, []],
        ];
    }

    /**
     * @param array<int, int[]> $linksByEntity [entityId => [meetingId, ...]]
     * @return array<int, array<string, int>>
     */
    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        $allIds = collect($linksByEntity)->flatten()->filter()->unique()->values();
        if ($allIds->isEmpty()) {
            return [];
        }

        $meetings = Meeting::query()
            ->whereIn('id', $allIds->all())
            ->withCount(['agendaItems', 'notes'])
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($linksByEntity as $entityId => $meetingIds) {
            $count = 0;
            $agenda = 0;
            $notes = 0;
            $lastDays = null;

            foreach (array_unique($meetingIds) as $mid) {
                $meeting = $meetings->get($mid);
                if (! $meeting) {
                    continue;
                }

                $count++;
                $agenda += (int) ($meeting->agenda_items_count ?? 0);
                $notes += (int) ($meeting->notes_count ?? 0);

                if ($meeting->start_date) {
                    $days = (int) $meeting->start_date->diffInDays(now());
                    $lastDays = $lastDays === null ? $days : min($lastDays, $days);
                }
            }

            $metrics = [
                'meetings_count' => $count,
                'meeting_agenda_items' => $agenda,
                'meeting_notes_count' => $notes,
            ];
            if ($lastDays !== null) {
                $metrics['meeting_days_since_last'] = $lastDays;
            }

            $result[$entityId] = $metrics;
        }

        return $result;
    }

    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }

    public function metricDefinitions(): array
    {
        return [
            'meetings_count' => [
                'label' => 'Meetings (Workspace)',
                'group' => 'meetings',
                'direction' => 'neutral',
                'unit' => 'count',
                'dimension' => EntityLinkRegistry::DIMENSION_ORG_CAPITAL,
                'type' => EntityLinkRegistry::TYPE_STOCK,
                'aggregation_mode' => 'rolled_up',
                'basis' => 'stichtag',
            ],
            'meeting_agenda_items' => [
                'label' => 'Agenda-Punkte',
                'group' => 'meetings',
                'direction' => 'neutral',
                'unit' => 'count',
                'dimension' => EntityLinkRegistry::DIMENSION_ORG_CAPITAL,
                'type' => EntityLinkRegistry::TYPE_STOCK,
                'basis' => 'stichtag',
            ],
            'meeting_notes_count' => [
                'label' => 'Meeting-Notizen',
                'group' => 'meetings',
                'direction' => 'neutral',
                'unit' => 'count',
                'dimension' => EntityLinkRegistry::DIMENSION_ORG_CAPITAL,
                'type' => EntityLinkRegistry::TYPE_STOCK,
                'basis' => 'stichtag',
            ],
            'meeting_days_since_last' => [
                'label' => 'Tage seit letztem Meeting',
                'group' => 'meetings',
                'direction' => 'neutral',
                'unit' => 'days',
                'dimension' => EntityLinkRegistry::DIMENSION_ENERGY,
                'type' => EntityLinkRegistry::TYPE_MODULATOR,
                'basis' => 'modulator_factor',
            ],
        ];
    }
}
