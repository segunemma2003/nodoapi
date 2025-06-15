<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Business;
use App\Models\SupportTicket;
use App\Models\SupportTicketResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BusinessSupportController extends Controller
{
  /**
     * Get tickets for authenticated business
     */
    public function getMyTickets(Request $request)
    {
        $business = Auth::user();
        if (!$business || !$business  instanceof Business) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $query = $business->supportTickets()
            ->with(['createdBy', 'assignedTo', 'resolvedBy']);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $tickets = $query->orderBy('created_at', 'desc')
                        ->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Support tickets retrieved successfully',
            'data' => $tickets,
            'summary' => $business->getSupportStats(),
        ]);
    }

    /**
     * Get ticket details (business can only see their own tickets)
     */
    public function getTicketDetails(SupportTicket $ticket)
    {
        $business = Auth::user();

        if (!$business || !$business  instanceof Business) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        if ($ticket->business_id !== $business->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found or access denied'
            ], 404);
        }

        $ticket->load(['createdBy', 'assignedTo', 'resolvedBy', 'responses.user', 'activities.user']);

        return response()->json([
            'success' => true,
            'message' => 'Ticket details retrieved successfully',
            'data' => $ticket
        ]);
    }

    /**
     * Create new support ticket
     */
    public function createTicket(Request $request)
    {
        $business = Auth::user();

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'in:low,medium,high,urgent',
            'category' => 'required|in:technical_issue,payment_issue,account_issue,feature_request,billing_inquiry,general_inquiry,bug_report,other',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,txt,doc,docx|max:5120',
        ]);

        DB::beginTransaction();
        try {
            // Handle file uploads
            $attachmentPaths = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('support_attachments', 'private');
                    $attachmentPaths[] = [
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                    ];
                }
            }

            $ticket = SupportTicket::create([
                'title' => $request->title,
                'description' => $request->description,
                'priority' => $request->input('priority', 'medium'),
                'category' => $request->category,
                'business_id' => $business->id,
                'created_by' => $business->id, // Business creating their own ticket
                'attachments' => $attachmentPaths,
                'status' => 'open',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Support ticket created successfully',
                'data' => $ticket
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
     * Add response to ticket
     */
    public function addResponse(Request $request, SupportTicket $ticket)
    {
        $business = Auth::user();

        if ($ticket->business_id !== $business->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found or access denied'
            ], 404);
        }

        $request->validate([
            'message' => 'required|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,txt,doc,docx|max:5120',
        ]);

        DB::beginTransaction();
        try {
            // Handle file uploads
            $attachmentPaths = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('support_attachments/' . $ticket->id, 'private');
                    $attachmentPaths[] = [
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                    ];
                }
            }

            $response = SupportTicketResponse::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $business->id,
                'message' => $request->message,
                'response_type' => 'customer_response',
                'attachments' => $attachmentPaths,
            ]);

            // Update ticket status if it was resolved/closed
            if (in_array($ticket->status, ['resolved', 'closed'])) {
                $ticket->update(['status' => 'open']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Response added successfully',
                'data' => $response
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
     * Close ticket (customer satisfaction)
     */
    public function closeTicket(Request $request, SupportTicket $ticket)
    {
        $business = Auth::user();

        if ($ticket->business_id !== $business->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found or access denied'
            ], 404);
        }

        if ($ticket->status !== 'resolved') {
            return response()->json([
                'success' => false,
                'message' => 'Can only close resolved tickets'
            ], 400);
        }

        $request->validate([
            'satisfaction_rating' => 'nullable|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $ticket->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            // Add closing response with feedback
            if ($request->satisfaction_rating || $request->feedback) {
                $message = "Customer closed ticket.";
                if ($request->satisfaction_rating) {
                    $message .= " Rating: {$request->satisfaction_rating}/5";
                }
                if ($request->feedback) {
                    $message .= " Feedback: {$request->feedback}";
                }

                SupportTicketResponse::create([
                    'support_ticket_id' => $ticket->id,
                    'user_id' => $business->id,
                    'message' => $message,
                    'response_type' => 'customer_response',
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ticket closed successfully',
                'data' => $ticket->fresh()
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
}
