<?php
require_once __DIR__ . '/Model.php';

class Week extends Model {
    protected static $table = 'weeks';
    protected static $allowedColumns = ['id', 'week_number', 'year', 'status', 'needs_recalculation', 'created_at', 'updated_at'];
}
