<?php

class Availability extends Model {
    protected static $table = 'availabilities';
    protected static $allowedColumns = ['id', 'child_id', 'slot_id', 'is_available', 'submitted_at'];
}
