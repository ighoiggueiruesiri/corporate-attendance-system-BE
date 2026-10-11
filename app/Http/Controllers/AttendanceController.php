<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Attendance;
use App\Models\User;

class AttendanceController extends Controller
{
    /**
     * A clock-in older than this is treated as an abandoned shift
     * (the person forgot to clock out), so it no longer blocks a new clock-in.
     */
    private const MAX_SHIFT_HOURS = 16;

    /**
     * Calendar date (Lagos time) stamped on each record, for reporting only.
     * It is NOT used for the clock-in/out rules, so shifts can span midnight.
     */
    private function localToday(): string
    {
        return now('Africa/Lagos')->toDateString();
    }

    /**
     * The user's currently open shift (their latest record is a recent
     * clock_in), or null if they are not clocked in.
     */
    private function openShift(int $userId): ?Attendance
    {
        $last = Attendance::where('user_id', $userId)->orderByDesc('id')->first();

        if (!$last || $last->type !== 'clock_in') {
            return null;
        }

        if ($last->created_at->lt(now()->subHours(self::MAX_SHIFT_HOURS))) {
            return null; // abandoned shift
        }

        return $last;
    }

    /** Returns an error message if the action isn't allowed, otherwise null. */
    private function ruleViolation(string $type, ?Attendance $open): ?string
    {
        if ($type === 'clock_in' && $open) {
            return 'You are already clocked in. Please clock out first.';
        }

        if ($type === 'clock_out' && !$open) {
            return 'You are not clocked in. Please clock in first.';
        }

        return null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:clock_in,clock_out',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo' => 'required|string', // base64 data URL, not an uploaded file
        ]);

        $userId = $request->user()->id;
        $type   = $request->type;

        // Fast, non-locking pre-check so obviously invalid requests
        // don't waste time on photo processing and geocoding.
        if ($message = $this->ruleViolation($type, $this->openShift($userId))) {
            return response()->json(['message' => $message], 409);
        }

        // ---- Photo validation ----
        $photoData = $request->input('photo');

        if (!preg_match('/^data:image\/(\w+);base64,/', $photoData, $matches)) {
            return response()->json(['message' => 'The photo field must be a valid base64 image.'], 422);
        }

        $extension = strtolower($matches[1]);
        if (!in_array($extension, ['jpeg', 'jpg', 'png', 'webp'])) {
            return response()->json(['message' => 'Unsupported image type.'], 422);
        }

        $base64 = substr($photoData, strpos($photoData, ',') + 1);
        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            return response()->json(['message' => 'Could not decode photo.'], 422);
        }

        if (strlen($decoded) > 5 * 1024 * 1024) {
            return response()->json(['message' => 'Photo is too large.'], 422);
        }

        if (@getimagesizefromstring($decoded) === false) {
            return response()->json(['message' => 'File is not a valid image.'], 422);
        }

        // Resize + recompress so a multi-hundred-KB webcam capture doesn't
        // sit on disk at full size. Always saved as .jpg after this step.
        [$optimized, $extension] = $this->optimizeImage($decoded);

        $filename = 'attendances/' . $userId . '_' . now()->format('YmdHis') . '_' . uniqid() . '.' . $extension;
        Storage::disk('public')->put($filename, $optimized);

        // Slow network call done BEFORE taking the lock.
        $address = $this->reverseGeocode($request->latitude, $request->longitude);

        // ---- Authoritative check + insert, serialized per user ----
        // Locking the user's row means two simultaneous requests (double-tap)
        // run one after the other, so the second sees the first's record.
        $error = DB::transaction(function () use ($userId, $type, $request, $address, $filename) {
            User::whereKey($userId)->lockForUpdate()->first();

            if ($message = $this->ruleViolation($type, $this->openShift($userId))) {
                return $message;
            }

            Attendance::create([
                'user_id'    => $userId,
                'type'       => $type,
                'latitude'   => $request->latitude,
                'longitude'  => $request->longitude,
                'address'    => $address,
                'photo_path' => $filename,
                'date'       => $this->localToday(),
            ]);

            return null;
        });

        if ($error) {
            // Lost the race — remove the orphaned photo.
            Storage::disk('public')->delete($filename);
            return response()->json(['message' => $error], 409);
        }

        return response()->json(['message' => 'Success']);
    }

    /**
     * Is the logged-in user currently on shift?
     * Used by the clock page to enable/disable its buttons.
     */
    public function status(Request $request)
    {
        $open = $this->openShift($request->user()->id);

        return response()->json([
            'clocked_in' => $open !== null,
            'since'      => $open?->created_at?->toIso8601String(),
        ]);
    }

    public function index(Request $request)
    {
        if (!$request->user()->is_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Attendance::with('user');

        if ($request->filled('staff_id')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('staff_id', 'like', '%' . $request->staff_id . '%');
            });
        }

        if ($request->filled('date')) {
            $query->where('date', $request->date);
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 100)); // clamp between 1 and 100

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($perPage)
        );
    }

    /**
     * Stream a stored attendance photo. Avoids depending on the
     * public/storage symlink, which many shared hosts block (symlink()
     * disabled) or which `php artisan storage:link` fails to create.
     */
    public function photo(Request $request, string $filename)
    {
        if (!$request->user()->is_admin) {
            abort(403);
        }

        // Prevent path traversal — only allow the exact stored filename shape
        if (!preg_match('/^[\w\-]+\.(jpe?g|png|webp)$/i', $filename)) {
            abort(404);
        }

        $path = 'attendances/' . $filename;

        if (!Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return response(
            Storage::disk('public')->get($path),
            200,
            ['Content-Type' => Storage::disk('public')->mimeType($path)]
        );
    }

    /**
     * Resize to a sane max width and re-encode as JPEG at moderate quality.
     * A raw webcam capture can easily be 500KB-1MB+; this typically brings
     * an attendance selfie down to well under 100KB without visibly hurting
     * the ability to identify who's in the photo.
     *
     * Falls back to storing the original, unmodified bytes if the GD
     * extension isn't available on this host (some shared hosts disable
     * extensions selectively — same class of issue as the fileinfo problem).
     *
     * @return array{0: string, 1: string} [binary contents, file extension]
     */
    private function optimizeImage(string $binary): array
    {
        if (!function_exists('imagecreatefromstring')) {
            Log::warning('GD extension unavailable — storing photo unoptimized.');
            return [$binary, 'jpg'];
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            Log::warning('GD could not decode image — storing original bytes.');
            return [$binary, 'jpg'];
        }

        // Normalize orientation isn't handled here (no EXIF rotation), but
        // react-webcam captures are already upright in practice.
        $maxWidth = 800;
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) round($height * ($maxWidth / $width));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 75); // quality 0-100; 75 is a solid size/quality balance
        $optimized = ob_get_clean();
        imagedestroy($image);

        return [$optimized, 'jpg'];
    }

    /**
     * Turn lat/lng into a human-readable address using OpenStreetMap's
     * free Nominatim reverse-geocoding endpoint. Never blocks or fails the
     * clock-in itself — falls back to null and the frontend can show the
     * raw coordinates or a map link instead.
     */
    private function reverseGeocode(float $lat, float $lng): ?string
    {
        try {
            $response = Http::withHeaders([
                    // Nominatim's usage policy blocks generic/placeholder identifiers
                    // (e.g. Laravel's default "Laravel" app name or hello@example.com) —
                    // this must be a real, identifying value or every request gets a 403.
                    'User-Agent' => 'Univasa Clockin/1.0 (contact: admin@univasa.com)',
                ])
                ->timeout(3)
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'json',
                ]);

            if ($response->successful()) {
                return $response->json('display_name');
            }

            Log::warning('Reverse geocode returned a non-success response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            // A slow/unavailable geocoder should never block a clock-in, but log it
            // so failures are visible instead of silently falling back forever.
            Log::warning('Reverse geocode failed: ' . $e->getMessage());
        }

        return null;
    }
}