<?php

namespace App\Http\Middleware;

use App\Api\ApiError;
use App\Internal\InternalSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn /internal/* nếu thiếu chữ ký đúng. Dùng: ->middleware('internal:tick') hoặc 'internal:deploy'.
 */
class VerifyInternalSignature
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $secret = (string) config("internal.{$scope}_secret");

        // Chưa đặt secret (máy dev, hoặc production chưa cài xong) thì đường này coi như không có.
        if ($secret === '') {
            abort(404);
        }

        $valid = InternalSignature::verify(
            (string) $request->header(InternalSignature::HEADER, ''),
            $secret,
            $request->method(),
            $request->getPathInfo(),
            $request->getContent(),
            now()->getTimestamp(),
        );

        if (! $valid) {
            return ApiError::json(401, 'invalid_signature', 'Thiếu chữ ký, chữ ký sai, hoặc lệch giờ quá 5 phút.');
        }

        return $next($request);
    }
}
