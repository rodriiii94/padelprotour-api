<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use GdImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AvatarController extends Controller
{
    /** Lado (px) de la foto guardada. */
    private const SIZE = 512;

    /** Tope de píxeles de la imagen original (≈ 25 megapíxeles). */
    private const MAX_PIXELS = 25_000_000;

    /**
     * Sube la foto de perfil. Se recorta al centro en cuadrado, se reduce y se vuelve a
     * codificar como JPEG: así se descartan los metadatos (EXIF, incluida la ubicación GPS)
     * y lo que se sirve nunca es el archivo original del usuario.
     */
    public function store(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $bytes = $request->file('avatar')->get();

        // Antes de decodificar: una imagen pequeña en disco puede ocupar gigas en memoria.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['avatar' => ['La imagen es demasiado grande o no es válida.']]);
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['avatar' => ['No se pudo leer la imagen.']]);
        }

        $image = $this->orient($image, $bytes);

        if (min(imagesx($image), imagesy($image)) < 64) {
            throw ValidationException::withMessages(['avatar' => ['La imagen es demasiado pequeña (mínimo 64 px).']]);
        }

        $path = 'avatars/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($path, $this->encodeSquare($image));

        $user = $request->user();
        $user->removeAvatarFile();
        $user->forceFill(['avatar_path' => $path])->save();

        return response()->json($user->append(['name_change_available_at', 'has_password', 'avatar_url']));
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        $user->removeAvatarFile();
        $user->forceFill(['avatar_path' => null])->save();

        return response()->json($user->append(['name_change_available_at', 'has_password', 'avatar_url']));
    }

    /**
     * Aplica la orientación EXIF de las fotos de móvil, que si no salen giradas.
     */
    private function orient(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function encodeSquare(GdImage $image): string
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $side = min($width, $height);

        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled(
            $square, $image,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE,
            $side, $side,
        );

        ob_start();
        imagejpeg($square, null, 85);

        return (string) ob_get_clean();
    }
}
