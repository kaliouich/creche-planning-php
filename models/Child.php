<?php
require_once __DIR__ . '/Model.php';

class Child extends Model {
    protected static $table = 'children';
    protected static $allowedColumns = ['id', 'first_name', 'last_name', 'parent_id', 'is_active', 'age_group', 'parent1_first_name', 'parent2_first_name', 'parent1_email', 'parent2_email'];
}
