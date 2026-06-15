{{-- resources/views/admin/proof-of-delivery/pdf.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Proof of Delivery - Trip #{{ $proofOfDelivery->trip_id }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #2563eb;
        }
        .header p {
            margin: 5px 0 0;
            color: #666;
        }
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .section-title {
            background: #f3f4f6;
            padding: 8px 12px;
            font-weight: bold;
            margin-bottom: 15px;
            border-left: 4px solid #2563eb;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 3px;
        }
        .info-value {
            font-size: 14px;
            font-weight: 500;
        }
        .photos {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 10px;
        }
        .photo {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .photo img {
            width: 100%;
            height: auto;
            display: block;
        }
        .signature {
            border: 1px solid #e5e7eb;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
        }
        .signature img {
            max-width: 300px;
            height: auto;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 20px;
        }
        .map-link {
            color: #2563eb;
            text-decoration: none;
        }
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Proof of Delivery</h1>
        <p>Document ID: POD-{{ str_pad($proofOfDelivery->id, 6, '0', STR_PAD_LEFT) }}</p>
        <p>Generated: {{ now()->format('d M Y H:i:s') }}</p>
    </div>

    <div class="section">
        <div class="section-title">Delivery Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Receiver Name</div>
                <div class="info-value">{{ $proofOfDelivery->receiver_name }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Delivery Time</div>
                <div class="info-value">{{ $proofOfDelivery->formatted_timestamp }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">GPS Location</div>
                <div class="info-value">
                    @if($proofOfDelivery->geolocation_lat && $proofOfDelivery->geolocation_long)
                        Lat: {{ number_format($proofOfDelivery->geolocation_lat, 6) }}<br>
                        Long: {{ number_format($proofOfDelivery->geolocation_long, 6) }}
                    @else
                        Not available
                    @endif
                </div>
            </div>
            @if($proofOfDelivery->notes)
            <div class="info-item">
                <div class="info-label">Delivery Notes</div>
                <div class="info-value">{{ $proofOfDelivery->notes }}</div>
            </div>
            @endif
        </div>
    </div>

    <div class="section">
        <div class="section-title">Trip Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Trip ID</div>
                <div class="info-value">#{{ $trip->id }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Driver</div>
                <div class="info-value">{{ $trip->driver?->user?->name ?? 'N/A' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Customer</div>
                <div class="info-value">{{ $trip->customer?->name ?? 'N/A' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Price</div>
                <div class="info-value">${{ number_format($trip->price, 2) }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Payment Method</div>
                <div class="info-value">{{ ucfirst($trip->payment_method ?? 'N/A') }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Route Information</div>
        <div class="info-item">
            <div class="info-label">Pickup Location</div>
            <div class="info-value">{{ $trip->origin_address ?? 'N/A' }}</div>
        </div>
        <div class="info-item mt-2">
            <div class="info-label">Dropoff Location</div>
            <div class="info-value">{{ $trip->destination_address ?? 'N/A' }}</div>
        </div>
        @if($trip->distance)
        <div class="info-item mt-2">
            <div class="info-label">Distance</div>
            <div class="info-value">{{ number_format($trip->distance, 2) }} km</div>
        </div>
        @endif
    </div>

    @if(!empty($pdfPhotos))
    <div class="section">
        <div class="section-title">Delivery Photos</div>
        <div class="photos">
            @foreach($pdfPhotos as $photo)
            <div class="photo">
                <img src="{{ $photo }}" alt="Delivery photo">
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if(!empty($pdfSignature))
    <div class="section">
        <div class="section-title">Customer Signature</div>
        <div class="signature">
            <img src="{{ $pdfSignature }}" alt="Signature">
            <p class="mt-2" style="font-size: 12px;">Digitally signed by {{ $proofOfDelivery->receiver_name }}</p>
        </div>
    </div>
    @endif

    <div class="footer">
        <p>This is a system-generated document. Valid proof of delivery for the specified trip.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>
