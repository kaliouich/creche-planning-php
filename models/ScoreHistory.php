<?php
require_once __DIR__ . '/Model.php';

class ScoreHistory extends Model {
    protected static $table = 'score_history';
    protected static $allowedColumns = ['id', 'child_id', 'week_number', 'year', 'score_before', 'permanences_done', 'permanences_due', 'score_after', 'snapshot_at'];
}
