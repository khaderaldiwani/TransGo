<?php
namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuthService
{
    protected $otpService;

    public function __construct(OtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    public function register($data,string $registerRole)
    {  
       
        return DB::transaction(function () use ($data,$registerRole) {

        $user =User::where('phone', $data['phone'])->first();
        $email=User::where('email',$data['email'])->first();
        if ($user) {
            throw new \RuntimeException("رقم الهاتف مستخدم بالفعل", 409);
        }
        if ($email) {
            throw new \RuntimeException(" الايميل مستخدم بالفعل", 409);
        }
        $user = User::create([
                'full_name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                
                'password' => Hash::make($data['password']),
                'account_status' => 0,
            ]);

            // attach role passenger
            $role = Role::where('name', $registerRole)->first();
            if (!$role) {
                throw new \RuntimeException("الدور المطلوب غير موجود: $registerRole", 500);
            }

            $user->roles()->attach($role->id);

            // generate OTP
            $this->otpService->generate($user);
            return [
                'user' => $user,
                
            ];
        });
    }

    public function login(array $data,String $loginRole,?string $loginSecondRole=null): array
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw new RuntimeException('المدخلات غير صحيحة', 401);
        }

        if ($user->account_status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('الحساب غير مفعل', 403);
        }

        $user->load('roles');
        $roles = $user->roles->pluck('name')->values()->all();
        $primaryRole = $roles[0] ?? null;
        $correctRole=false;
        foreach ($roles as $role) {
            if ($role === $loginRole || $role === $loginSecondRole) {
                $correctRole=true;
                break;
            }
        }
        if (!$correctRole) {
            throw new RuntimeException('الحساب لا يملك صلاحية الدخول لهذا القسم', 403);
        }

        if ($user->isBackofficeUser() && $user->must_change_password) {
            return [
                'user' => $user,
                'token' => null,
                'role' => $primaryRole,
                'roles' => $roles,
                'must_change_password' => true,
            ];
        }

        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'role' => $primaryRole,
            'roles' => $roles,
            'must_change_password' => false,
        ];
    }

    public function changeInitialPassword(array $data): void
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['current_password'], $user->password)) {
            throw new RuntimeException('المدخلات غير صحيحة', 401);
        }

        if (!$user->isBackofficeUser()) {
            throw new RuntimeException('الحساب لا يملك صلاحية تغيير كلمة المرور', 403);
        }

        if (!$user->must_change_password) {
            throw new RuntimeException('تغيير كلمة المرور غير مطلوب لهذا الحساب', 409);
        }

        $user->update([
            'password' => Hash::make($data['new_password']),
            'must_change_password' => false,
        ]);

        $user->tokens()->delete();
    }
    function resetPassword(array $data)
    {
        $user=User::where('email', $data['email'])->first(); 
        if (!$user) {
            throw new RuntimeException('الحساب غير موجود', 404);
        }

        return $user->update([
            'password' => Hash::make($data['password']),
        ]);
    }
}
