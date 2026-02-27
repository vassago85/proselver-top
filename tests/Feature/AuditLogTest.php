<?php

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('audit log entries cannot be updated', function () {
    $log = AuditLog::create([
        'action_type' => 'test',
        'entity_type' => 'test',
        'entity_id' => 1,
    ]);

    $log->action_type = 'modified';
    $result = $log->save();

    expect($result)->toBeFalse();

    $fresh = AuditLog::find($log->id);
    expect($fresh->action_type)->toBe('test');
});

test('audit log entries cannot be deleted', function () {
    $log = AuditLog::create([
        'action_type' => 'test',
        'entity_type' => 'test',
        'entity_id' => 1,
    ]);

    $log->delete();

    expect(AuditLog::find($log->id))->not->toBeNull();
});
