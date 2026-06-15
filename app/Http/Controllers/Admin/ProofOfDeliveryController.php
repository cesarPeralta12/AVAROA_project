<?php
// app/Http/Controllers/Admin/ProofOfDeliveryController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProofOfDelivery;
use App\Models\Trip;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

class ProofOfDeliveryController extends Controller
{
    private function checkSession()
    {
        if (!Session::has('LoggedIn')) {
            return redirect('Userlogin')->with('fail', 'Please login first.')->send();
        }
        return User::findOrFail(Session::get('LoggedIn'));
    }

    /**
     * Display a listing of proof of deliveries
     */
    public function index(Request $request)
    {
        $user_session = $this->checkSession();

        $query = ProofOfDelivery::with(['trip.driver.user', 'trip.customer']);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('trip', function($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhereHas('driver.user', function($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('customer', function($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            })->orWhere('receiver_name', 'like', "%{$search}%");
        }

        // Date filter
        if ($request->filled('date_from')) {
            $query->whereDate('timestamp', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('timestamp', '<=', $request->date_to);
        }

        // Driver filter
        if ($request->filled('driver_id')) {
            $query->whereHas('trip', function($q) use ($request) {
                $q->where('driver_id', $request->driver_id);
            });
        }

        $proofOfDeliveries = $query->orderBy('timestamp', 'desc')->paginate(20);

        // Get drivers for filter dropdown
        $drivers = Driver::with('user')->whereHas('user')->get();

        // Stats
        $stats = [
            'total' => ProofOfDelivery::count(),
            'today' => ProofOfDelivery::whereDate('timestamp', today())->count(),
            'this_week' => ProofOfDelivery::whereBetween('timestamp', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month' => ProofOfDelivery::whereMonth('timestamp', now()->month)->count(),
        ];

        return view('admin.proof-of-delivery.index', compact('proofOfDeliveries', 'stats', 'drivers', 'user_session'));
    }

    /**
     * Display the specified proof of delivery
     */
    public function show($id)
    {
        $user_session = $this->checkSession();

        $proofOfDelivery = ProofOfDelivery::with(['trip.driver.user', 'trip.customer', 'trip.vehicle'])->findOrFail($id);

        // Get trip details
        $trip = $proofOfDelivery->trip;

        // Get driver request if exists
        $driverRequest = null;
        if ($trip) {
            $driverRequest = DB::table('driver_requests')->where('trip_id', $trip->id)->first();
        }

        return view('admin.proof-of-delivery.show', compact('proofOfDelivery', 'trip', 'driverRequest', 'user_session'));
    }

    /**
     * Display proof of delivery by trip
     */
    public function showByTrip($tripId)
    {
        $user_session = $this->checkSession();

        $trip = Trip::with(['driver.user', 'customer'])->findOrFail($tripId);
        $proofOfDelivery = ProofOfDelivery::where('trip_id', $tripId)->first();

        if (!$proofOfDelivery) {
            return redirect()->route('admin.trips.show', $tripId)
                ->with('error', 'No proof of delivery found for this trip.');
        }

        return redirect()->route('admin.proof-of-delivery.show', $proofOfDelivery->id);
    }

    /**
     * Download proof of delivery as PDF
     */
    public function downloadPdf($id)
    {
        $user_session = $this->checkSession();

        $proofOfDelivery = ProofOfDelivery::with(['trip.driver.user', 'trip.customer'])->findOrFail($id);
        $trip = $proofOfDelivery->trip;

        // Convert photo URLs/paths to absolute local paths for dompdf
        $pdfPhotos = collect($proofOfDelivery->getAllPhotosAttribute())
            ->map(fn($p) => $this->toAbsolutePath($p))
            ->filter()
            ->values()
            ->toArray();

        $pdfSignature = $proofOfDelivery->signature
            ? $this->toAbsolutePath($proofOfDelivery->signature)
            : null;

        $pdf = Pdf::loadView('admin.proof-of-delivery.pdf', compact(
            'proofOfDelivery', 'trip', 'user_session', 'pdfPhotos', 'pdfSignature'
        ))->setPaper('a4', 'portrait');

        $filename = 'POD-' . str_pad($proofOfDelivery->id, 6, '0', STR_PAD_LEFT) . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Convert a stored photo path (relative or full URL) to an absolute filesystem path.
     */
    private function toAbsolutePath(?string $path): ?string
    {
        if (!$path) return null;

        // Already an absolute filesystem path
        if (str_starts_with($path, '/') && !str_starts_with($path, '//') && file_exists($path)) {
            return $path;
        }

        // Full URL — strip the origin to get the relative path
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $parsed   = parse_url($path);
            $relative = ltrim($parsed['path'] ?? '', '/');
            $abs      = public_path($relative);
            return file_exists($abs) ? $abs : null;
        }

        // Relative path (e.g. "uploads/proof/xxx.jpg" or "/uploads/proof/xxx.jpg")
        $abs = public_path(ltrim($path, '/'));
        return file_exists($abs) ? $abs : null;
    }

    /**
     * Delete proof of delivery
     */
    public function destroy($id)
    {
        try {
            $user_session = $this->checkSession();

            $proofOfDelivery = ProofOfDelivery::findOrFail($id);
            $proofOfDelivery->delete();

            return response()->json([
                'success' => true,
                'message' => 'Proof of delivery deleted successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete proof of delivery.'
            ], 500);
        }
    }

    /**
     * Export proof of deliveries
     */
    public function export(Request $request)
    {
        $user_session = $this->checkSession();

        $query = ProofOfDelivery::with(['trip.driver.user', 'trip.customer']);

        // Apply filters
        if ($request->filled('date_from')) {
            $query->whereDate('timestamp', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('timestamp', '<=', $request->date_to);
        }

        $deliveries = $query->orderBy('timestamp', 'desc')->get();

        // Generate CSV
        $filename = 'proof_of_deliveries_' . now()->format('Y-m-d_His') . '.csv';
        $handle = fopen('php://temp', 'w+');

        // Add headers
        fputcsv($handle, [
            'ID', 'Trip ID', 'Driver Name', 'Customer Name', 'Receiver Name',
            'Timestamp', 'Location (Lat, Long)', 'Notes', 'Photos Count'
        ]);

        // Add data
        foreach ($deliveries as $delivery) {
            fputcsv($handle, [
                $delivery->id,
                $delivery->trip_id,
                $delivery->trip?->driver?->user?->name ?? 'N/A',
                $delivery->trip?->customer?->name ?? 'N/A',
                $delivery->receiver_name,
                $delivery->formatted_timestamp,
                $delivery->geolocation_lat && $delivery->geolocation_long
                    ? "{$delivery->geolocation_lat}, {$delivery->geolocation_long}"
                    : 'N/A',
                $delivery->notes ?? 'N/A',
                count($delivery->getAllPhotosAttribute())
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response($csvContent, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"$filename\"");
    }
}
