<?php

require_once('../../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/hierarchy/type/position/lib.php');


///
/// Setup / loading data
///

// Competency id
$userid = required_param('user', PARAM_INT);

// Setup page
require_login();

// Check permissions
$personalcontext = get_context_instance(CONTEXT_USER, $userid);
$systemcontext = get_context_instance(CONTEXT_SYSTEM);

$can_edit = false;
if (has_capability('moodle/local:assignuserposition', $systemcontext)) {
    $can_edit = true;
}
elseif (has_capability('moodle/local:assignuserposition', $personalcontext)) {
    $can_edit = true;
}
elseif ($USER->id == $user->id &&
    has_capability('moodle/local:assignselfposition', $systemcontext)) {
    $can_edit = true;
}

if (!$can_edit) {
    error('You do not have the permissions to assign this user a position');
}

// Load potential managers for this user
$managers = get_records_sql(
    "
        SELECT
            u.id,        
            u.firstname,
            u.lastname,
            ra.id AS ra
        FROM
            {$CFG->prefix}user u
        INNER JOIN
            {$CFG->prefix}role_assignments ra
         ON u.id = ra.userid
        INNER JOIN
            {$CFG->prefix}role r
         ON ra.roleid = r.id
        WHERE
            r.shortname = 'manager'
        ORDER BY
            u.firstname,
            u.lastname
    "
);


///
/// Display page
///

?>

<div class="selectmanager">

<h2><?php echo get_string('choosemanager', 'position'); ?></h2>

<ul id="managers" class="filetree">
<?php

// Foreach manager
if ($managers) {
    foreach ($managers as $manager) {
        $li_class = '';
        $span_class = 'clickable';

        echo '<li class="'.$li_class.'" id="manager_list_'.$manager->id.'">';
        echo '<span id="man_'.$manager->id.'" class="'.$span_class.'">'.fullname($manager).'</span>';
        echo '</li>'.PHP_EOL;
    }
} else {
    echo '<li><span class="empty">'.get_string('nomanagersavailable', 'position').'</span></li>'.PHP_EOL;
}

echo '</ul></div>';