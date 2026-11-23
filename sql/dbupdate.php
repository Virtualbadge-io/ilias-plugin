<#1>
<?php
/**
 * Virtualbadge.io plugin: initial tables.
 * All table names start with rep_robj_xvbd_ as required by ILIAS (ilPluginDBUpdate).
 *
 * @var ilDBInterface $ilDB
 */
if (!$ilDB->tableExists('rep_robj_xvbd_data')) {
    $ilDB->createTable('rep_robj_xvbd_data', [
        'obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'course_ref_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'course_obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'org' => ['type' => 'text', 'length' => 100, 'notnull' => false],
        'template_id' => ['type' => 'text', 'length' => 100, 'notnull' => false],
        'template_name' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'template_type' => ['type' => 'text', 'length' => 20, 'notnull' => false],
        'field_map' => ['type' => 'clob', 'notnull' => false],
        'trigger_type' => ['type' => 'text', 'length' => 20, 'notnull' => true, 'default' => 'completion'],
        'trigger_ref_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'trigger_obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'min_percent' => ['type' => 'float', 'notnull' => false],
        'send_email' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 1],
        'completion_issued' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xvbd_data', ['obj_id']);
    $ilDB->addIndex('rep_robj_xvbd_data', ['course_obj_id'], 'i1');
    $ilDB->addIndex('rep_robj_xvbd_data', ['trigger_obj_id'], 'i2');
}
?>
<#2>
<?php
if (!$ilDB->tableExists('rep_robj_xvbd_issue')) {
    $ilDB->createTable('rep_robj_xvbd_issue', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'usr_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'org' => ['type' => 'text', 'length' => 100, 'notnull' => false],
        'template_id' => ['type' => 'text', 'length' => 100, 'notnull' => false],
        'reference' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'status' => ['type' => 'text', 'length' => 20, 'notnull' => true, 'default' => 'queued'],
        'source' => ['type' => 'text', 'length' => 20, 'notnull' => false],
        'remote_id' => ['type' => 'text', 'length' => 100, 'notnull' => false],
        'replace_remote_id' => ['type' => 'text', 'length' => 100, 'notnull' => false],
        'previous_remote_id' => ['type' => 'text', 'length' => 100, 'notnull' => false],
        'certificate_url' => ['type' => 'text', 'length' => 1000, 'notnull' => false],
        'validation_url' => ['type' => 'text', 'length' => 1000, 'notnull' => false],
        'completed_at' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'attempts' => ['type' => 'integer', 'length' => 2, 'notnull' => true, 'default' => 0],
        'check_no' => ['type' => 'integer', 'length' => 2, 'notnull' => true, 'default' => 0],
        'next_run' => ['type' => 'integer', 'length' => 4, 'notnull' => false],
        'email_sent' => ['type' => 'integer', 'length' => 2, 'notnull' => true, 'default' => 0],
        'time_email_sent' => ['type' => 'integer', 'length' => 4, 'notnull' => false],
        'last_error' => ['type' => 'text', 'length' => 4000, 'notnull' => false],
        'time_created' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'time_modified' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'time_issued' => ['type' => 'integer', 'length' => 4, 'notnull' => false],
        'time_checked' => ['type' => 'integer', 'length' => 4, 'notnull' => false],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xvbd_issue', ['id']);
    $ilDB->createSequence('rep_robj_xvbd_issue');
    $ilDB->addUniqueConstraint('rep_robj_xvbd_issue', ['obj_id', 'usr_id'], 'c1');
    $ilDB->addIndex('rep_robj_xvbd_issue', ['status', 'next_run'], 'i1');
    $ilDB->addIndex('rep_robj_xvbd_issue', ['usr_id'], 'i2');
    $ilDB->addIndex('rep_robj_xvbd_issue', ['org', 'status'], 'i3');
}
?>
<#3>
<?php
if (!$ilDB->tableExists('rep_robj_xvbd_cache')) {
    $ilDB->createTable('rep_robj_xvbd_cache', [
        'cache_key' => ['type' => 'text', 'length' => 64, 'notnull' => true],
        'cache_value' => ['type' => 'clob', 'notnull' => false],
        'time_modified' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xvbd_cache', ['cache_key']);
}
if (!$ilDB->tableExists('rep_robj_xvbd_calls')) {
    $ilDB->createTable('rep_robj_xvbd_calls', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'api_key' => ['type' => 'text', 'length' => 40, 'notnull' => true],
        'call_time' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xvbd_calls', ['id']);
    $ilDB->createSequence('rep_robj_xvbd_calls');
    $ilDB->addIndex('rep_robj_xvbd_calls', ['api_key', 'call_time'], 'i1');
}
?>
