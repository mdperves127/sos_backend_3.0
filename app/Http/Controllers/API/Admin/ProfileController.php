<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminProfileOtpMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    private const OTP_TTL_MINUTES = 5;

    public function AdminProfile()
    {
        $user = $this->authenticatedUser();

        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        return response()->json([
            'status' => 200,
            'user' => $user,
        ]);
    }

    public function AdminUpdateProfile(Request $request)
    {
        $user = $this->authenticatedUser();

        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'number' => 'nullable|string|max:20',
            'number2' => 'nullable|string|max:20',
            'image' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->messages(),
            ]);
        }

        $user->name = $request->name;
        $user->number = $request->input('number');
        $user->number2 = $request->input('number2');

        if ($request->hasFile('image')) {
            if (!empty($user->image)) {
                $oldPath = public_path($user->image);
                if (File::exists($oldPath)) {
                    File::delete($oldPath);
                }
            }

            $user->image = fileUpload($request->file('image'), 'uploads/admin');
        }

        $user->save();

        return response()->json([
            'status' => 200,
            'message' => 'Profile Update Successfully',
            'user' => $user->fresh(),
        ]);
    }

    public function sendEmailChangeOtp(Request $request)
    {
        $user = $this->authenticatedUser();

        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $validator = Validator::make($request->all(), [
            'new_email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->messages(),
            ]);
        }

        $newEmail = strtolower(trim((string) $request->new_email));

        if ($newEmail === strtolower((string) $user->email)) {
            return response()->json([
                'status' => 400,
                'message' => 'New email must be different from your current email.',
            ]);
        }

        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            return response()->json([
                'status' => 400,
                'message' => 'This email is already in use.',
            ]);
        }

        $code = $this->storeAndSendOtp(
            user: $user,
            purpose: 'email_change',
            destinationEmail: $newEmail,
            extra: ['new_email' => $newEmail]
        );

        if ($code instanceof \Illuminate\Http\JsonResponse) {
            return $code;
        }

        return response()->json([
            'status' => 200,
            'message' => 'Verification code sent to your new email.',
            'email' => $newEmail,
        ]);
    }

    public function verifyEmailChangeOtp(Request $request)
    {
        $user = $this->authenticatedUser();

        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $validator = Validator::make($request->all(), [
            'new_email' => 'required|email|max:255',
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->messages(),
            ]);
        }

        $newEmail = strtolower(trim((string) $request->new_email));
        $payload = $this->consumeOtp($user->id, 'email_change', (string) $request->code);

        if ($payload instanceof \Illuminate\Http\JsonResponse) {
            return $payload;
        }

        if (($payload['new_email'] ?? '') !== $newEmail) {
            return response()->json([
                'status' => 400,
                'message' => 'Email does not match the verification request.',
            ]);
        }

        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            return response()->json([
                'status' => 400,
                'message' => 'This email is already in use.',
            ]);
        }

        $user->email = $newEmail;
        $user->email_verified_at = now();
        $user->save();

        return response()->json([
            'status' => 200,
            'message' => 'Email updated successfully.',
            'user' => $user->fresh(),
        ]);
    }

    public function sendPasswordChangeOtp(Request $request)
    {
        $user = $this->authenticatedUser();

        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:8|max:32|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->messages(),
            ]);
        }

        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json([
                'status' => 400,
                'message' => 'Old Password Not Match!',
            ]);
        }

        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'status' => 400,
                'message' => 'New password must be different from your current password.',
            ]);
        }

        if (empty($user->email)) {
            return response()->json([
                'status' => 400,
                'message' => 'No email is set on this account to send a verification code.',
            ]);
        }

        $code = $this->storeAndSendOtp(
            user: $user,
            purpose: 'password_change',
            destinationEmail: (string) $user->email,
            extra: [
                'new_password_hash' => Hash::make($request->new_password),
            ]
        );

        if ($code instanceof \Illuminate\Http\JsonResponse) {
            return $code;
        }

        return response()->json([
            'status' => 200,
            'message' => 'Verification code sent to your email.',
            'email' => $user->email,
        ]);
    }

    public function verifyPasswordChangeOtp(Request $request)
    {
        $user = $this->authenticatedUser();

        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->messages(),
            ]);
        }

        $payload = $this->consumeOtp($user->id, 'password_change', (string) $request->code);

        if ($payload instanceof \Illuminate\Http\JsonResponse) {
            return $payload;
        }

        if (empty($payload['new_password_hash'])) {
            return response()->json([
                'status' => 400,
                'message' => 'Password change request expired. Please try again.',
            ]);
        }

        $user->password = $payload['new_password_hash'];
        $user->save();

        return response()->json([
            'status' => 200,
            'message' => 'Password updated successfully.',
            'user' => $user->fresh(),
        ]);
    }

    private function authenticatedUser()
    {
        $authUser = request()->user();

        if (!$authUser || !isset($authUser->id)) {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = User::find($authUser->id);

        if (!$user) {
            return response()->json([
                'status' => 404,
                'message' => 'User not found',
            ], 404);
        }

        return $user;
    }

    private function otpCacheKey(int $userId, string $purpose): string
    {
        return "admin_profile_otp:{$userId}:{$purpose}";
    }

    private function storeAndSendOtp(
        User $user,
        string $purpose,
        string $destinationEmail,
        array $extra = []
    ) {
        $code = (string) random_int(100000, 999999);

        Cache::put(
            $this->otpCacheKey((int) $user->id, $purpose),
            array_merge($extra, [
                'code_hash' => Hash::make($code),
                'email' => strtolower($destinationEmail),
            ]),
            now()->addMinutes(self::OTP_TTL_MINUTES)
        );

        try {
            Mail::to($destinationEmail)->send(
                new AdminProfileOtpMail($code, $purpose, $user->name)
            );
        } catch (\Throwable $e) {
            Cache::forget($this->otpCacheKey((int) $user->id, $purpose));

            return response()->json([
                'status' => 500,
                'message' => 'Failed to send verification email. Please try again.',
            ], 500);
        }

        return $code;
    }

    private function consumeOtp(int $userId, string $purpose, string $code)
    {
        $key = $this->otpCacheKey($userId, $purpose);
        $payload = Cache::get($key);

        if (!$payload || empty($payload['code_hash'])) {
            return response()->json([
                'status' => 400,
                'message' => 'Verification code expired or not found. Please request a new code.',
            ]);
        }

        if (!Hash::check($code, $payload['code_hash'])) {
            return response()->json([
                'status' => 400,
                'message' => 'Invalid verification code.',
            ]);
        }

        Cache::forget($key);

        return $payload;
    }
}
