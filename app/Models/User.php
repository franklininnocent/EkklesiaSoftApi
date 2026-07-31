<?php

namespace App\Models;

/**
 * App User Model - Extends the Authentication Module User
 * 
 * This class exists to satisfy Laravel Passport's expectation of an 
 * App\Models\User class while using the modular User model from 
 * the Authentication module.
 */
class User extends \Modules\Authentication\Models\User
{
    // Inherits all functionality from the Authentication module's User model
}


