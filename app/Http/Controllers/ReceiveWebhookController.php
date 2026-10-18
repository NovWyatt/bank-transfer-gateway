<?php

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Models\ProviderConnection;
use App\Payments\Ingest\IngestWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReceiveWebhookController
{
    public function __invoke(Request $request, string $provider, string $uuid, IngestWebhook $ingest): JsonResponse
    {
        // Sai nhà cung cấp hay sai uuid đều trả 404 như nhau, không cho dò kết nối có tồn tại hay không.
        $connection = ProviderConnection::query()
            ->where('uuid', $uuid)
            ->where('provider', Provider::from($provider))
            ->first();

        abort_if($connection === null, 404);

        return $ingest->handle($request, $connection);
    }
}
