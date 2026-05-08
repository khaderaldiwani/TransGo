<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ComplaintReportService
{
    public function getComplaintReport(array $filters): array
    {
        $fromDate = $filters['from_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $toDate = $filters['to_date'] ?? Carbon::now()->format('Y-m-d');
        $complainantType = $filters['complainant_type'] ?? null;
        $complaintStatus = $filters['complaint_status'] ?? null;
        $complaintType = $filters['complaint_type'] ?? null;
        $userRole = $filters['user_role'] ?? Role::ROLE_ADMIN;
        $employeeGovernorates = $filters['employee_governorates'] ?? null;

        $query = $this->buildComplaintQuery(
            $fromDate,
            $toDate,
            $complainantType,
            $complaintStatus,
            $complaintType,
            $userRole,
            $employeeGovernorates
        );

        $complaints = (clone $query)
            ->with('complainant')
            ->orderBy('created_at', 'desc')
            ->get();

        $statusCounts = $this->calculateStatusCounts($complaints);
        $typeCounts = $this->groupCountsBy($query, 'complaint_type');
        $complainantTypeCounts = $this->groupCountsBy($query, 'complainant_role');
        $byDay = $this->groupByDay($query);

        $totalComplaints = $complaints->count();
        $totalRides = $this->countTotalRides($fromDate, $toDate, $userRole, $employeeGovernorates);
        $complaintsVsRidesRatio = $totalRides > 0
            ? round(($totalComplaints / $totalRides) * 100, 2)
            : 0;

        $topType = collect($typeCounts)
            ->sortByDesc('count')
            ->first();
        $mostCommonType = is_array($topType) ? ($topType['complaint_type'] ?? null) : null;

        return [
            'filters_applied' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'complainant_type' => $complainantType,
                'complaint_status' => $complaintStatus,
                'complaint_type' => $complaintType,
            ],
            'summary' => [
                'total_complaints' => $totalComplaints,
                'open_complaints' => $statusCounts['open'],
                'in_progress_complaints' => $statusCounts['in_progress'],
                'closed_complaints' => $statusCounts['closed'],
                'most_common_complaint_type' => $mostCommonType,
                'complaints_vs_rides_ratio' => $complaintsVsRidesRatio,
            ],
            'breakdown' => [
                'by_status' => $this->buildStatusBreakdown($statusCounts, $totalComplaints),
                'by_type' => $this->buildTypeBreakdown($typeCounts, $totalComplaints),
                'by_complainant_type' => $this->buildComplainantTypeBreakdown($complainantTypeCounts, $totalComplaints),
                'by_day' => $byDay,
            ],
            'complaints_list' => $complaints->map(function (Complaint $complaint) {
                return [
                    'complaint_id' => $complaint->complaint_id,
                    'complainant_name' => $complaint->complainant?->full_name,
                    'complainant_type' => $complaint->complainant_role,
                    'complaint_type' => $complaint->complaint_type,
                    'status' => $this->normalizeStatusForResponse($complaint->status),
                    'description' => $complaint->description,
                    'created_at' => $complaint->created_at?->format('Y-m-d H:i:s'),
                    'resolved_at' => $complaint->resolved_at?->format('Y-m-d H:i:s'),
                ];
            })->values()->toArray(),
        ];
    }

    private function buildComplaintQuery(
        string $fromDate,
        string $toDate,
        ?string $complainantType,
        ?string $complaintStatus,
        ?string $complaintType,
        string $userRole,
        ?string $employeeGovernorates
    ) {
        $query = Complaint::query();

        if ($complainantType) {
            $query->where('complainant_role', $complainantType);
        }

        if ($complaintType) {
            $query->where('complaint_type', $complaintType);
        }

        if ($complaintStatus) {
            $query->where('status', $this->mapStatusFilter($complaintStatus));
        }

        $query->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate);

        if ($userRole === Role::ROLE_EMPLOYEE) {
            $allowedIds = $this->parseGovernorates($employeeGovernorates);

            $query->where(function ($query) use ($allowedIds) {
                $query->whereHas('trip', function ($query) use ($allowedIds) {
                    $query->whereIn('start_governorate_id', $allowedIds)
                        ->orWhereIn('end_governorate_id', $allowedIds);
                })
                ->orWhereHas('booking.trip', function ($query) use ($allowedIds) {
                    $query->whereIn('start_governorate_id', $allowedIds)
                        ->orWhereIn('end_governorate_id', $allowedIds);
                });
            });
        }

        return $query;
    }

    private function mapStatusFilter(string $status): string
    {
        return match ($status) {
            'open' => 'new',
            'closed' => 'completed',
            default => 'in_progress',
        };
    }

    private function normalizeStatusForResponse(string $status): string
    {
        return match ($status) {
            'new' => 'open',
            'completed' => 'closed',
            default => $status,
        };
    }

    private function calculateStatusCounts(Collection $complaints): array
    {
        $statusCounts = [
            'open' => 0,
            'in_progress' => 0,
            'closed' => 0,
        ];

        foreach ($complaints as $complaint) {
            $normalized = $this->normalizeStatusForResponse($complaint->status);
            if (isset($statusCounts[$normalized])) {
                $statusCounts[$normalized]++;
            }
        }

        return $statusCounts;
    }

    private function groupCountsBy($query, string $field): array
    {
        return (clone $query)
            ->select($field, DB::raw('count(*) as count'))
            ->groupBy($field)
            ->get()
            ->map(function ($item) use ($field) {
                return [
                    $field => $item->{$field},
                    'count' => (int) $item->count,
                ];
            })->values()->toArray();
    }

    private function groupByDay($query): array
    {
        return (clone $query)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as complaints_count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'complaints_count' => (int) $item->complaints_count,
                ];
            })->values()->toArray();
    }

    private function buildStatusBreakdown(array $statusCounts, int $total): array
    {
        return collect($statusCounts)->map(function ($count, $status) use ($total) {
            return [
                'status' => $status,
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        })->values()->toArray();
    }

    private function buildTypeBreakdown(array $typeCounts, int $total): array
    {
        return collect($typeCounts)->map(function ($item) use ($total) {
            return [
                'type' => $item['complaint_type'],
                'count' => $item['count'],
                'percentage' => $total > 0 ? round(($item['count'] / $total) * 100, 1) : 0,
            ];
        })->sortByDesc('count')->values()->toArray();
    }

    private function buildComplainantTypeBreakdown(array $complainantTypeCounts, int $total): array
    {
        return collect($complainantTypeCounts)->map(function ($item) use ($total) {
            return [
                'complainant_type' => $item['complainant_role'],
                'count' => $item['count'],
                'percentage' => $total > 0 ? round(($item['count'] / $total) * 100, 1) : 0,
            ];
        })->sortByDesc('count')->values()->toArray();
    }

    private function countTotalRides(string $fromDate, string $toDate, string $userRole, ?string $employeeGovernorates): int
    {
        $query = Booking::query()
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate);

        if ($userRole === Role::ROLE_EMPLOYEE && $employeeGovernorates) {
            $allowedIds = $this->parseGovernorates($employeeGovernorates);
            $query->whereHas('trip', function ($query) use ($allowedIds) {
                $query->whereIn('start_governorate_id', $allowedIds)
                    ->orWhereIn('end_governorate_id', $allowedIds);
            });
        }

        return $query->count();
    }

    private function parseGovernorates(?string $governorates): array
    {
        if (!$governorates) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $governorates))));
    }
}
