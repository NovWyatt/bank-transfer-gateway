<?php

namespace App\Payments\VietQr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Vẽ chuỗi VietQR thành SVG bằng PHP thuần (không cần GD, không gọi API ngoài).
 */
final class QrSvg
{
    public static function render(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            // Mức M chịu được khoảng 15% hỏng: đủ cho màn hình bị lóa hoặc chụp hơi mờ.
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'svgAddXmlHeader' => true,
            // Vẽ cả ô sáng để mã luôn có nền trắng: đặt trên nền bạc hà hay xám vẫn quét được.
            // Giữ màu mặc định đen #000 trên trắng #fff, độ tương phản tốt nhất cho máy quét.
            'drawLightModules' => true,
            'connectPaths' => true,
            'svgUseFillAttributes' => true,
        ]);

        return (new QRCode($options))->render($data);
    }
}
