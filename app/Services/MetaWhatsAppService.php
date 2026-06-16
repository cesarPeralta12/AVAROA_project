<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MetaWhatsAppService
{
    protected ?string $accessToken = null;
    protected ?string $phoneNumberId = null;
    protected string $apiVersion = 'v22.0';
    protected bool $configured = false;

    public function __construct()
    {
        $this->accessToken = config('services.meta.whatsapp.access_token');
        $this->phoneNumberId = config('services.meta.whatsapp.phone_number_id');
        $this->apiVersion = config('services.meta.whatsapp.api_version', 'v22.0');

        if (!empty($this->accessToken) && !empty($this->phoneNumberId)) {
            $this->configured = true;
        } else {
            Log::error('Meta WhatsApp not configured', [
                'has_token' => !empty($this->accessToken),
                'has_phone_id' => !empty($this->phoneNumberId)
            ]);
        }
    }

    public function sendMessage(string $phoneNumber, string $message): bool
    {
        if (!$this->configured) {
            Log::error('Meta WhatsApp not configured');
            return false;
        }

        try {
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'text',
                'text' => ['body' => $message]
            ];

            $t0 = hrtime(true);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout(3)
                ->timeout(6)
                ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", $payload);
            $ms = round((hrtime(true) - $t0) / 1e6);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['messages'][0]['id'])) {
                    Log::info('WhatsApp API sendMessage', [
                        'phone' => $phoneNumber,
                        'wamid' => $data['messages'][0]['id'],
                        'ms' => $ms,
                    ]);
                    $this->cacheSentMessage($phoneNumber, $message);
                    return true;
                }
            }

            Log::error('Failed to send message', [
                'phone' => $phoneNumber,
                'response' => $response->body(),
                'status' => $response->status(),
                'ms' => $ms,
            ]);
            return false;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Re-lanzar errores de red/timeout para que los jobs puedan reintentarse.
            Log::warning('WhatsApp connection timeout, will retry if in queue', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Message send exception', [
                'error' => $e->getMessage(),
                'phone' => $phoneNumber
            ]);
            return false;
        }
    }

    public function sendTemplateMessage(string $phoneNumber, string $templateName, array $parameters = []): bool
    {
        if (!$this->configured) {
            Log::error('Meta WhatsApp not configured');
            return false;
        }

        try {
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);

            $templateParams = [];
            foreach ($parameters as $key => $value) {
                $templateParams[] = [
                    'type' => 'text',
                    'text' => (string) $value
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => $this->getTemplateLanguage($templateName)],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => $templateParams
                        ]
                    ]
                ]
            ];

            $t0 = hrtime(true);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout(3)
                ->timeout(6)
                ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", $payload);
            $ms = round((hrtime(true) - $t0) / 1e6);

            $responseBody = $response->body();
            $responseData = $response->json();

            if ($response->successful() && isset($responseData['messages'][0]['id'])) {
                Log::info('WhatsApp API sendTemplate', [
                    'phone' => $phoneNumber,
                    'template' => $templateName,
                    'wamid' => $responseData['messages'][0]['id'],
                    'ms' => $ms,
                ]);
                return true;
            }

            Log::error('Template message failed', [
                'phone' => $phoneNumber,
                'template' => $templateName,
                'response' => $responseBody,
                'status' => $response->status(),
                'ms' => $ms,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Template send exception', [
                'error' => $e->getMessage(),
                'template' => $templateName,
                'phone' => $phoneNumber
            ]);
            return false;
        }
    }

    public function sendAssignedNotification(string $phoneNumber, string $serviceType, array $tripDetails, string $language = 'es'): bool
    {
        Log::info('Sending courier assigned notification', [
            'phone' => $phoneNumber,
            'language' => $language
        ]);

        $templateName = 'courier_assigned_sp';

        $parameters = [
            $tripDetails['driver_name'] ?? $tripDetails['couriername'] ?? 'Mensajero',
            $tripDetails['vehicle_type'] ?? $tripDetails['vehicletype'] ?? 'Vehículo',
            $tripDetails['origin'] ?? $tripDetails['pickup'] ?? 'No especificado',
            $tripDetails['destination'] ?? 'No especificado',
            $tripDetails['price'] ?? '0',
        ];

        return $this->sendTemplateMessage($phoneNumber, $templateName, $parameters);
    }

    public function sendCompletedNotification(string $phoneNumber, string $serviceType, array $tripDetails, string $language = 'es'): bool
    {
        Log::info('Sending delivery completed notification', [
            'phone' => $phoneNumber,
            'language' => $language
        ]);

        $templateName = 'delivery_completed_sp';

        $parameters = [
            $tripDetails['pickup'] ?? 'No especificado',
            $tripDetails['destination'] ?? 'No especificado',
            $tripDetails['drivername'] ?? $tripDetails['couriername'] ?? 'Mensajero',
            $tripDetails['price'] ?? '0',
        ];

        return $this->sendTemplateMessage($phoneNumber, $templateName, $parameters);
    }

    public function sendDriverRequestTemplate(string $phoneNumber, array $rideDetails): bool
    {
        $parameters = [
            $rideDetails['name'] ?? 'Cliente',
            $rideDetails['service_type'] ?? 'Delivery',
            $rideDetails['pickup'] ?? 'No especificado',
            $rideDetails['destination'] ?? 'No especificado',
            $rideDetails['price'] ?? '0',
            $rideDetails['instructions'] ?: 'Ninguna'
        ];

        $parameters = array_map(function ($v) {
            return empty($v) ? 'No proporcionado' : substr((string)$v, 0, 1024);
        }, $parameters);

        return $this->sendTemplateMessage($phoneNumber, 'driver_latest_request_sp', $parameters);
    }

    public function sendTripCostConfirmation(string $phoneNumber, array $tripDetails, string $templateName = 'trip_cost_confirmation_sp'): bool
    {
        $parameters = [
            $tripDetails['price'] ?? '0',
            $tripDetails['pickup'] ?? 'No especificado',
            $tripDetails['destination'] ?? 'No especificado',
        ];

        return $this->sendTemplateMessage($phoneNumber, $templateName, $parameters);
    }

    public function sendToUser(string $phoneNumber, string $message, ?string $fallbackTemplate = null, array $templateParams = []): bool
    {
        try {
            $sent = $this->sendMessage($phoneNumber, $message);
        } catch (\Exception $e) {
            $sent = false;
        }

        if (!$sent && $fallbackTemplate) {
            \App\Jobs\SendWhatsAppMessage::dispatch($phoneNumber, $message);
        }

        return $sent;
    }

    public function verifyWebhook(string $mode, string $token, string $challenge): ?string
    {
        $verifyToken = config('services.meta.whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            Log::info('Webhook verified successfully');
            return $challenge;
        }

        Log::error('Webhook verification failed', [
            'mode' => $mode,
            'token_matches' => $token === $verifyToken
        ]);
        return null;
    }

    public function processWebhook(array $payload): ?array
    {
        try {
            if (empty($payload['entry'])) {
                return null;
            }

            $entry = $payload['entry'][0];
            $changes = $entry['changes'][0] ?? null;

            if (!$changes || $changes['field'] !== 'messages') {
                return null;
            }

            $value = $changes['value'] ?? [];

            if (!empty($value['statuses'])) {
                $status = $value['statuses'][0];

                Log::info('Message status update', $status);

                return [
                    'type' => 'status',
                    'status' => $status['status'] ?? null,
                    'message_id' => $status['id'] ?? null,
                    'recipient' => $status['recipient_id'] ?? null,
                ];
            }

            $messages = $value['messages'] ?? [];

            if (empty($messages)) {
                return null;
            }

            $message = $messages[0];
            $contacts = $value['contacts'] ?? [];
            $contact = $contacts[0] ?? [];

            $phoneNumber = $contact['wa_id'] ?? $message['from'] ?? null;
            $profileName = $contact['profile']['name'] ?? 'Invitado';

            if (!$phoneNumber) {
                Log::error('No phone number in webhook payload');
                return null;
            }

            $phoneNumber = $this->formatIncomingPhoneNumber($phoneNumber);

            $messageData = $this->extractMessageContent($message);

            return [
                'type' => 'message',
                'chat_id' => $phoneNumber,
                'first_name' => $profileName,
                'user_message' => $messageData['text'] ?? '',
                'message_type' => $messageData['type'],
                'latitude' => $messageData['latitude'] ?? null,
                'longitude' => $messageData['longitude'] ?? null,
                'location_name' => $messageData['location_name'] ?? null,
                'address' => $messageData['address'] ?? null,
                'raw_payload' => $message
            ];
        } catch (\Exception $e) {
            Log::error('Webhook processing error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function extractMessageContent(array $message): array
    {
        $type = $message['type'] ?? 'text';

        $result = [
            'type' => $type,
            'text' => '',
            'latitude' => null,
            'longitude' => null,
            'location_name' => null,
            'address' => null
        ];

        switch ($type) {
            case 'text':
                $result['text'] = $message['text']['body'] ?? '';
                break;

            case 'location':
                $location = $message['location'] ?? [];
                $result['latitude'] = $location['latitude'] ?? null;
                $result['longitude'] = $location['longitude'] ?? null;
                $result['location_name'] = $location['name'] ?? null;
                $result['address'] = $location['address'] ?? null;
                $result['text'] = "#ATTACHMENT:location#" . ($location['name'] ?? 'Ubicación compartida') . "#" . ($location['latitude'] ?? '') . "#" . ($location['longitude'] ?? '');
                break;

            case 'interactive':
                if (isset($message['interactive']['button_reply'])) {
                    $result['text'] = $message['interactive']['button_reply']['title'] ?? '';
                } elseif (isset($message['interactive']['list_reply'])) {
                    $result['text'] = $message['interactive']['list_reply']['title'] ?? '';
                }
                break;

            default:
                $result['text'] = "[{$type} message]";
        }

        return $result;
    }

    public function isEchoMessage(string $phoneNumber, string $message): bool
    {
        $key = 'bot_sent_' . $phoneNumber;
        $hash = md5(strtolower(trim($message)));

        if (Cache::has($key . '_' . $hash)) {
            return true;
        }

        return $this->isLikelyBotMessage($message);
    }

    protected function isLikelyBotMessage(string $text): bool
    {
        $botPatterns = [
            '/^¡hola! bienvenido a delivery avaroa/i',
            '/^¿dónde recogemos tu paquete/i',
            '/^¡excelente! ¿a dónde entregamos tu paquete/i',
            '/^el servicio de entrega cuesta bs/i',
            '/^¿te gustaría que asignemos un (mensajero|conductor)/i',
            '/^buscando un (mensajero|conductor)/i',
            '/^buscando (mensajeros|conductores) disponibles/i',
            '/^✅\s*\*?¡?(mensajero|conductor) asignado/i',
            '/^✅\s*\*?¡?(conductor|mensajero) asignado/i',
            '/^(🚚|🚕)\s*\*?tu (mensajero|conductor) está en camino/i',
            '/^📍\s*\*?¡?tu (mensajero|conductor) llegó/i',
            '/^🚚 entrega completada/i',
            '/^✅\s*\*?¡(viaje|entrega) (completado|completada)/i',
            '/^gracias por elegir nuestro servicio/i',
            '/^📢 nueva solicitud de entrega/i',
            '/^hay una nueva solicitud de entrega/i',
        ];

        $lowerText = strtolower($text);
        foreach ($botPatterns as $pattern) {
            if (preg_match($pattern, $lowerText)) return true;
        }
        return false;
    }

    protected function cacheSentMessage(string $phoneNumber, string $message): void
    {
        $key = 'bot_sent_' . $phoneNumber;
        $hash = md5(strtolower(trim($message)));
        Cache::put($key . '_' . $hash, true, 60);
    }

    protected function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $phone = ltrim($phone, '0');

        if (strlen($phone) <= 10) {
            $phone = '591' . $phone;
        }

        return $phone;
    }

    protected function formatIncomingPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return ltrim($phone, '0');
    }

    protected function getTemplateLanguage(string $templateName): string
    {
        return 'es';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function getTemplates(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])->connectTimeout(5)->timeout(8)->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/message_templates");

            return $response->json()['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Get templates failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getPhoneNumberStatus(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])->connectTimeout(5)->timeout(8)->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}");

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Phone status failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function markAsRead(string $messageId): bool
    {
        if (!$this->configured || !$messageId) return false;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->connectTimeout(3)->timeout(5)->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Mark as read failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
