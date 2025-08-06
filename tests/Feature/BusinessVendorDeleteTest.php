<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Business;
use App\Models\Vendor;
use App\Models\PurchaseOrder;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class BusinessVendorDeleteTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $business;
    protected $vendor;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user
        $this->admin = User::factory()->create([
            'user_type' => 'admin',
            'role' => 'admin'
        ]);

        // Create business
        $this->business = Business::factory()->create([
            'created_by' => $this->admin->id,
            'is_active' => true
        ]);

        // Create vendor for the business
        $this->vendor = Vendor::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'approved',
            'is_active' => true
        ]);
    }

    /** @test */
    public function business_can_delete_their_vendor_without_purchase_orders()
    {
        $response = $this->actingAs($this->business, 'sanctum')
            ->deleteJson("/api/business/vendors/{$this->vendor->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Vendor deleted successfully'
            ]);

        $this->assertDatabaseMissing('vendors', [
            'id' => $this->vendor->id
        ]);
    }

    /** @test */
    public function business_cannot_delete_vendor_with_existing_purchase_orders()
    {
        // Create a purchase order for this vendor
        PurchaseOrder::factory()->create([
            'business_id' => $this->business->id,
            'vendor_id' => $this->vendor->id,
            'status' => 'approved'
        ]);

        $response = $this->actingAs($this->business, 'sanctum')
            ->deleteJson("/api/business/vendors/{$this->vendor->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete vendor with existing purchase orders'
            ]);

        $this->assertDatabaseHas('vendors', [
            'id' => $this->vendor->id
        ]);
    }

    /** @test */
    public function business_cannot_delete_vendor_with_pending_payments()
    {
        // Create a purchase order with pending payment
        $purchaseOrder = PurchaseOrder::factory()->create([
            'business_id' => $this->business->id,
            'vendor_id' => $this->vendor->id,
            'status' => 'approved'
        ]);

        Payment::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'business_id' => $this->business->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->business, 'sanctum')
            ->deleteJson("/api/business/vendors/{$this->vendor->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete vendor with pending payments'
            ]);

        $this->assertDatabaseHas('vendors', [
            'id' => $this->vendor->id
        ]);
    }

    /** @test */
    public function business_cannot_delete_vendor_belonging_to_another_business()
    {
        // Create another business and vendor
        $otherBusiness = Business::factory()->create([
            'created_by' => $this->admin->id
        ]);

        $otherVendor = Vendor::factory()->create([
            'business_id' => $otherBusiness->id
        ]);

        $response = $this->actingAs($this->business, 'sanctum')
            ->deleteJson("/api/business/vendors/{$otherVendor->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Vendor not found or access denied'
            ]);

        $this->assertDatabaseHas('vendors', [
            'id' => $otherVendor->id
        ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_vendor()
    {
        $response = $this->deleteJson("/api/business/vendors/{$this->vendor->id}");

        $response->assertStatus(401);
    }

    /** @test */
    public function admin_cannot_delete_vendor_through_business_endpoint()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/business/vendors/{$this->vendor->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ]);
    }

    /** @test */
    public function business_can_delete_vendor_with_completed_purchase_orders()
    {
        // Create a completed purchase order (all payments confirmed)
        $purchaseOrder = PurchaseOrder::factory()->create([
            'business_id' => $this->business->id,
            'vendor_id' => $this->vendor->id,
            'status' => 'approved',
            'payment_status' => 'paid'
        ]);

        Payment::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'business_id' => $this->business->id,
            'status' => 'confirmed'
        ]);

        $response = $this->actingAs($this->business, 'sanctum')
            ->deleteJson("/api/business/vendors/{$this->vendor->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Vendor deleted successfully'
            ]);

        $this->assertDatabaseMissing('vendors', [
            'id' => $this->vendor->id
        ]);
    }

    /** @test */
    public function delete_vendor_returns_proper_response_structure()
    {
        $response = $this->actingAs($this->business, 'sanctum')
            ->deleteJson("/api/business/vendors/{$this->vendor->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'deleted_vendor' => [
                        'id',
                        'name',
                        'email',
                        'vendor_code',
                        'business_id',
                        'business_name',
                        'status',
                        'created_at'
                    ],
                    'deletion_summary' => [
                        'vendor_id',
                        'vendor_name',
                        'vendor_code',
                        'deleted_at',
                        'deleted_by'
                    ]
                ]
            ]);
    }
}
