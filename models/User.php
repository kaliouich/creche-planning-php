<?php

class User extends Model {
    protected static $table = 'users';
    protected static $allowedColumns = ['id', 'email', 'role', 'first_name', 'last_name', 'is_active'];
}
