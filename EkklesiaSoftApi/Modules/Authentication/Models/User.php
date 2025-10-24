<?php
/**
 * Created by PhpStorm.
 * User: franklin
 * Date: 10/10/25
 * Time: 12:59 PM
 */

namespace Modules\Authentication\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
       // 'tenant_id', // Optional for multi-tenant
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}