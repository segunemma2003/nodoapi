<?php

// app/Http/Controllers/Api/AdminSupportController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketResponse;
use App\Models\User;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Admin Support",
 *     description="Support ticket management for administrators"
 * )
 */
class AdminSupportController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/admin/support/tickets",
     *     summary="Get all support tickets with filtering",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Support tickets retrieved")
     * )
     */
    public function getTickets(Request $request)
    {
        $query = SupportTicket::with(['business', 'createdBy', 'assignedTo', 'resolvedBy']);

        // Filters
        if ($request->filled('status')) {
            if (is_array($request->status)) {
                $query->whereIn('status', $request->status);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('priority')) {
            if (is_array($request->priority)) {
                $query->whereIn('priority', $request->priority);
            } else {
                $query->where('priority', $request->priority);
            }
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('assigned_to')) {
            if ($request->assigned_to === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $request->assigned_to);
            }
        }

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->business_id);
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        if ($request->filled('overdue_only')) {
            $query->overdue();
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('ticket_number', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $tickets = $query->paginate($request->input('per_page', 20));

        // Add calculated fields
        $tickets->getCollection()->transform(function ($ticket) {
            $ticket->is_overdue = $ticket->isOverdue();
            $ticket->hours_open = $ticket->getHoursOpen();
            $ticket->response_time_minutes = $ticket->getResponseTime();
            $ticket->sla_status = $ticket->getSlaStatus();
            $ticket->priority_color = $ticket->getPriorityColor();
            $ticket->status_color = $ticket->getStatusColor();
            return $ticket;
        });

        return response()->json([
            'success' => true,
            'message' => 'Support tickets retrieved successfully',
            'data' => $tickets,
            'summary' => [
                'total_tickets' => SupportTicket::count(),
                'open_tickets' => SupportTicket::where('status', 'open')->count(),
                'in_progress_tickets' => SupportTicket::where('status', 'in_progress')->count(),
                'overdue_tickets' => SupportTicket::overdue()->count(),
                'unassigned_tickets' => SupportTicket::whereNull('assigned_to')->count(),
                'high_priority_open' => SupportTicket::whereIn('priority', ['high', 'urgent'])
                                                    ->whereNotIn('status', ['resolved', 'closed'])
                                                    ->count(),
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/support/tickets/{ticket}",
     *     summary="Get specific ticket details",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Ticket details retrieved")
     * )
     */
    public function getTicketDetails(SupportTicket $ticket)
    {
        $ticket->load([
            'business',
            'createdBy',
            'assignedTo',
            'resolvedBy',
            'responses.user',
            'activities.user'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket details retrieved successfully',
            'data' => [
                'ticket' => $ticket,
                'calculated_metrics' => [
                    'is_overdue' => $ticket->isOverdue(),
                    'hours_open' => $ticket->getHoursOpen(),
                    'response_time_minutes' => $ticket->getResponseTime(),
                    'sla_status' => $ticket->getSlaStatus(),
                    'priority_color' => $ticket->getPriorityColor(),
                    'status_color' => $ticket->getStatusColor(),
                ],
                'related_info' => [
                    'total_responses' => $ticket->responses()->count(),
                    'admin_responses' => $ticket->responses()->where('response_type', 'admin_response')->count(),
                    'customer_responses' => $ticket->responses()->where('response_type', 'customer_response')->count(),
                    'has_solution' => $ticket->responses()->where('is_solution', true)->exists(),
                ],
                'business_context' => $ticket->business ? [
                    'total_tickets' => $ticket->business->supportTickets()->count(),
                    'open_tickets' => $ticket->business->supportTickets()->where('status', 'open')->count(),
                    'payment_score' => $ticket->business->getPaymentScore(),
                    'credit_utilization' => $ticket->business->getCreditUtilization(),
                ] : null,
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/support/tickets",
     *     summary="Create new support ticket",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=201, description="Support ticket created")
     * )
     */
    public function createTicket(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'required|in:low,medium,high,urgent',
            'category' => 'required|in:technical_issue,payment_issue,account_issue,feature_request,billing_inquiry,general_inquiry,bug_report,other',
            'business_id' => 'nullable|exists:businesses,id',
            'assigned_to' => 'nullable|exists:users,id',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'is_internal' => 'boolean',
            'estimated_hours' => 'nullable|numeric|min:0|max:999.99',
        ]);

        DB::beginTransaction();
        try {
            $ticket = SupportTicket::create([
                'title' => $request->title,
                'description' => $request->description,
                'priority' => $request->priority,
                'category' => $request->category,
                'business_id' => $request->business_id,
                'created_by' => Auth::id(),
                'assigned_to' => $request->assigned_to,
                'tags' => $request->tags,
                'is_internal' => $request->is_internal ?? false,
                'estimated_hours' => $request->estimated_hours,
                'status' => 'open',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Support ticket created successfully',
                'data' => $ticket->load(['business', 'createdBy', 'assignedTo'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create support ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/admin/support/tickets/{ticket}",
     *     summary="Update ticket (status, priority, assignment)",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Ticket updated")
     * )
     */
    public function updateTicket(Request $request, SupportTicket $ticket)
    {
        $request->validate([
            'title' => 'string|max:255',
            'description' => 'string',
            'status' => 'in:open,in_progress,pending_customer,resolved,closed',
            'priority' => 'in:low,medium,high,urgent',
            'category' => 'in:technical_issue,payment_issue,account_issue,feature_request,billing_inquiry,general_inquiry,bug_report,other',
            'assigned_to' => 'nullable|exists:users,id',
            'resolution' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'estimated_hours' => 'nullable|numeric|min:0|max:999.99',
            'actual_hours' => 'nullable|numeric|min:0|max:999.99',
        ]);

        DB::beginTransaction();
        try {
            $oldData = $ticket->toArray();

            $ticket->update($request->only([
                'title',
                'description',
                'status',
                'priority',
                'category',
                'assigned_to',
                'resolution',
                'tags',
                'estimated_hours',
                'actual_hours',
            ]));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ticket updated successfully',
                'data' => $ticket->fresh(['business', 'createdBy', 'assignedTo', 'resolvedBy'])
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/support/tickets/{ticket}/assign",
     *     summary="Assign ticket to staff",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Ticket assigned")
     * )
     */
    public function assignTicket(Request $request, SupportTicket $ticket)
    {
        $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $assignee = User::findOrFail($request->assigned_to);

            // Verify assignee is admin
            if (!$assignee->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only assign tickets to admin users'
                ], 400);
            }

            $oldAssignedTo = $ticket->assigned_to;
            $ticket->update([
                'assigned_to' => $request->assigned_to,
                'status' => $ticket->status === 'open' ? 'in_progress' : $ticket->status,
            ]);

            // Add assignment note if provided
            if ($request->notes) {
                SupportTicketResponse::create([
                    'support_ticket_id' => $ticket->id,
                    'user_id' => Auth::id(),
                    'message' => "Ticket assigned to {$assignee->name}. Notes: {$request->notes}",
                    'response_type' => 'internal_note',
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Ticket assigned to {$assignee->name} successfully",
                'data' => [
                    'ticket' => $ticket->fresh(['assignedTo']),
                    'assignment_details' => [
                        'previous_assignee' => $oldAssignedTo ? User::find($oldAssignedTo)?->name : null,
                        'new_assignee' => $assignee->name,
                        'assigned_by' => Auth::user()->name,
                        'notes' => $request->notes,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/support/tickets/{ticket}/close",
     *     summary="Close ticket with resolution",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Ticket closed")
     * )
     */
    public function closeTicket(Request $request, SupportTicket $ticket)
    {
        $request->validate([
            'resolution' => 'required|string',
            'resolution_type' => 'in:resolved,closed',
            'notify_customer' => 'boolean',
        ]);

        if (in_array($ticket->status, ['resolved', 'closed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket is already resolved or closed'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $resolutionType = $request->input('resolution_type', 'resolved');

            $ticket->update([
                'status' => $resolutionType,
                'resolution' => $request->resolution,
                'resolved_by' => Auth::id(),
                'resolved_at' => now(),
                'closed_at' => $resolutionType === 'closed' ? now() : null,
            ]);

            // Add resolution response
            $response = SupportTicketResponse::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'message' => $request->resolution,
                'response_type' => 'admin_response',
                'is_solution' => true,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Ticket {$resolutionType} successfully",
                'data' => [
                    'ticket' => $ticket->fresh(['resolvedBy']),
                    'resolution_details' => [
                        'resolution_type' => $resolutionType,
                        'resolved_by' => Auth::user()->name,
                        'resolved_at' => $ticket->resolved_at,
                        'total_hours_open' => $ticket->getHoursOpen(),
                        'response_time_minutes' => $ticket->getResponseTime(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to close ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/support/tickets/{ticket}/response",
     *     summary="Add response to ticket",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=201, description="Response added")
     * )
     */
    public function addResponse(Request $request, SupportTicket $ticket)
    {
        $request->validate([
            'message' => 'required|string',
            'response_type' => 'in:internal_note,admin_response',
            'is_solution' => 'boolean',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,txt,doc,docx|max:5120',
        ]);

        DB::beginTransaction();
        try {
            // Handle file uploads
            $attachmentPaths = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('support_attachments/' . $ticket->id, 's3');
                    $attachmentPaths[] = [
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ];
                }
            }

            $response = SupportTicketResponse::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'message' => $request->message,
                'response_type' => $request->input('response_type', 'admin_response'),
                'is_solution' => $request->input('is_solution', false),
                'attachments' => $attachmentPaths,
            ]);

            // Update ticket status if it was pending customer response
            if ($ticket->status === 'pending_customer' && $request->response_type === 'admin_response') {
                $ticket->update(['status' => 'in_progress']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Response added successfully',
                'data' => $response->load('user')
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add response',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/support/stats",
     *     summary="Get support statistics",
     *     tags={"Admin Support"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Support statistics retrieved")
     * )
     */
    public function getStats(Request $request)
    {
        $period = $request->input('period', '30'); // days
        $startDate = now()->subDays($period);

        // Basic counts
        $stats = [
            'overview' => [
                'total_tickets' => SupportTicket::count(),
                'open_tickets' => SupportTicket::where('status', 'open')->count(),
                'in_progress_tickets' => SupportTicket::where('status', 'in_progress')->count(),
                'resolved_tickets' => SupportTicket::where('status', 'resolved')->count(),
                'closed_tickets' => SupportTicket::where('status', 'closed')->count(),
                'overdue_tickets' => SupportTicket::overdue()->count(),
                'unassigned_tickets' => SupportTicket::whereNull('assigned_to')->count(),
            ],

            'priority_breakdown' => [
                'urgent' => SupportTicket::where('priority', 'urgent')->count(),
                'high' => SupportTicket::where('priority', 'high')->count(),
                'medium' => SupportTicket::where('priority', 'medium')->count(),
                'low' => SupportTicket::where('priority', 'low')->count(),
            ],

            'category_breakdown' => SupportTicket::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray(),

            'recent_period_stats' => [
                'period_days' => $period,
                'tickets_created' => SupportTicket::where('created_at', '>=', $startDate)->count(),
                'tickets_resolved' => SupportTicket::where('resolved_at', '>=', $startDate)->count(),
                'tickets_closed' => SupportTicket::where('closed_at', '>=', $startDate)->count(),
            ],

            'performance_metrics' => [
                'avg_first_response_time' => $this->getAverageFirstResponseTime(),
                'avg_resolution_time' => $this->getAverageResolutionTime(),
                'sla_compliance_rate' => $this->getSlaComplianceRate(),
                'customer_satisfaction' => null, // Placeholder for future implementation
            ],

            'team_performance' => $this->getTeamPerformanceStats($startDate),

            'business_ticket_stats' => [
                'businesses_with_tickets' => SupportTicket::distinct('business_id')->count('business_id'),
                'avg_tickets_per_business' => SupportTicket::whereNotNull('business_id')->count() /
                    max(1, SupportTicket::distinct('business_id')->count('business_id')),
                'top_ticket_businesses' => $this->getTopTicketBusinesses(),
            ],

            'trends' => [
                'daily_tickets_last_week' => $this->getDailyTicketTrends(7),
                'weekly_tickets_last_month' => $this->getWeeklyTicketTrends(4),
            ],
        ];

        return response()->json([
            'success' => true,
            'message' => 'Support statistics retrieved successfully',
            'data' => $stats
        ]);
    }

    /**
     * Helper methods for statistics
     */
    private function getAverageFirstResponseTime()
    {
        $tickets = SupportTicket::whereNotNull('first_response_at')->get();

        if ($tickets->isEmpty()) {
            return null;
        }

        $totalMinutes = $tickets->sum(function($ticket) {
            return $ticket->created_at->diffInMinutes($ticket->first_response_at);
        });

        return round($totalMinutes / $tickets->count(), 2);
    }

    private function getAverageResolutionTime()
    {
        $resolvedTickets = SupportTicket::whereNotNull('resolved_at')->get();

        if ($resolvedTickets->isEmpty()) {
            return null;
        }

        $totalHours = $resolvedTickets->sum(function($ticket) {
            return $ticket->created_at->diffInHours($ticket->resolved_at);
        });

        return round($totalHours / $resolvedTickets->count(), 2);
    }

    private function getSlaComplianceRate()
    {
        $totalTickets = SupportTicket::whereIn('status', ['resolved', 'closed'])->count();

        if ($totalTickets === 0) {
            return null;
        }

        $slaCompliantTickets = SupportTicket::whereIn('status', ['resolved', 'closed'])
            ->get()
            ->filter(function($ticket) {
                return !$ticket->isOverdue();
            })
            ->count();

        return round(($slaCompliantTickets / $totalTickets) * 100, 2);
    }

    private function getTeamPerformanceStats($startDate)
    {
        return User::where('user_type', 'admin')
            ->with(['assignedTickets' => function($q) use ($startDate) {
                $q->where('created_at', '>=', $startDate);
            }])
            ->get()
            ->map(function($user) use ($startDate) {
                $assignedTickets = $user->assignedTickets;
                $resolvedTickets = $assignedTickets->where('status', 'resolved');

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'assigned_tickets' => $assignedTickets->count(),
                    'resolved_tickets' => $resolvedTickets->count(),
                    'resolution_rate' => $assignedTickets->count() > 0 ?
                        round(($resolvedTickets->count() / $assignedTickets->count()) * 100, 2) : 0,
                    'avg_resolution_time' => $resolvedTickets->isEmpty() ? null :
                        round($resolvedTickets->avg(function($ticket) {
                            return $ticket->created_at->diffInHours($ticket->resolved_at);
                        }), 2),
                ];
            });
    }

    private function getTopTicketBusinesses($limit = 5)
    {
        return Business::withCount('supportTickets')
            ->orderBy('support_tickets_count', 'desc')
            ->limit($limit)
            ->get()
            ->map(function($business) {
                return [
                    'business_id' => $business->id,
                    'business_name' => $business->name,
                    'ticket_count' => $business->support_tickets_count,
                ];
            });
    }

    private function getDailyTicketTrends($days)
    {
        $trends = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $trends[$date] = SupportTicket::whereDate('created_at', $date)->count();
        }
        return $trends;
    }

    private function getWeeklyTicketTrends($weeks)
    {
        $trends = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $startOfWeek = now()->subWeeks($i)->startOfWeek();
            $endOfWeek = now()->subWeeks($i)->endOfWeek();
            $weekLabel = $startOfWeek->format('M d') . ' - ' . $endOfWeek->format('M d');
            $trends[$weekLabel] = SupportTicket::whereBetween('created_at', [$startOfWeek, $endOfWeek])->count();
        }
        return $trends;
    }
}
