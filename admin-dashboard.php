<?php
// admin-dashboard.php
require_once 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Admin and SuperAdmin have separate landing pages. SuperAdmin gets every
// feature on superadmin-dashboard.php; this page is for ADMIN only — except
// when superadmin-dashboard.php includes us, in which case we render the
// full superadmin view.
$__from_superadmin_wrapper = defined('SUPERADMIN_DASHBOARD_ENTRY');

if (!in_array($_SESSION['user_type'], ['ADMIN', 'SUPERADMIN'], true)) {
    header('Location: dashboard.php'); // Redirect to regular user dashboard
    exit;
}

if ($_SESSION['user_type'] === 'SUPERADMIN' && !$__from_superadmin_wrapper) {
    header('Location: superadmin-dashboard.php');
    exit;
}

if ($_SESSION['user_type'] === 'ADMIN' && $__from_superadmin_wrapper) {
    header('Location: admin-dashboard.php');
    exit;
}

$current_user = get_current_session_user();
$is_superadmin = ($_SESSION['user_type'] === 'SUPERADMIN');


// Get dashboard statistics
function get_dashboard_stats($conn) {
    $stats = [
        'total_users' => 0,
        'total_children' => 0,
        'total_appointments' => 0,
        'total_vaccinations' => 0
    ];
    
    try {
        // Total users
        $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM users");
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $stats['total_users'] = $row['total'] ?? 0;
        }
        
        // Total patients (children)
        $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM patients");
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $stats['total_children'] = $row['total'] ?? 0;
        }
        
        // Total appointments
        $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments");
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $stats['total_appointments'] = $row['total'] ?? 0;
        }
        
        // Total vaccinations
        $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM vaccination_records");
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $stats['total_vaccinations'] = $row['total'] ?? 0;
        }
    } catch (Exception $e) {
        error_log("Error getting dashboard stats: " . $e->getMessage());
    }
    
    return $stats;
}

// Get recent activity
function get_recent_activity($conn, $limit = 10) {
    $activity = [];
    $limit = intval($limit);

    try {
        $query = "SELECT al.*, u.first_name, u.last_name
                  FROM activity_logs al
                  LEFT JOIN users u ON al.user_id = u.id
                   ORDER BY al.created_at DESC
                  LIMIT ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $activity[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error getting recent activity: " . $e->getMessage());
    }
    
    return $activity;
}

// Get data for different sections
function get_all_users($conn) {
    $users = [];
    $query = "SELECT * FROM users ORDER BY created_at DESC";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = $row;
        }
    }
    return $users;
}

function get_doctor_schedules($conn) {
    $schedules = [];
    $query = "SELECT ds.*, u.first_name, u.last_name 
              FROM doctor_schedules ds 
              JOIN users u ON ds.doctor_id = u.id 
              ORDER BY 
                FIELD(ds.day_of_week, 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'),
                ds.start_time";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $schedules[] = $row;
        }
    }
    return $schedules;
}

function get_all_services($conn) {
    $services = [];
    $query = "SELECT * FROM services ORDER BY name";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $services[] = $row;
        }
    }
    return $services;
}

function get_all_appointments($conn) {
    $appointments = [];
    $query = "SELECT a.*, 
              p.first_name as patient_first_name, p.last_name as patient_last_name,
              d.first_name as doctor_first_name, d.last_name as doctor_last_name,
              u.first_name as created_by_first_name, u.last_name as created_by_last_name
              FROM appointments a
              JOIN patients p ON a.patient_id = p.id
              JOIN users d ON a.doctor_id = d.id
              LEFT JOIN users u ON a.created_by = u.id
              ORDER BY a.appointment_date DESC, a.appointment_time DESC";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $appointments[] = $row;
        }
    }
    return $appointments;
}

// All registered children/patients with parent + recent-vitals info, used by
// the superadmin "Child / Patient Profiles" section.
function get_all_patients($conn) {
    $patients = [];
    $sql = "SELECT p.*, u.first_name AS parent_first_name, u.last_name AS parent_last_name,
                   u.email AS parent_email, u.phone AS parent_phone
            FROM patients p
            JOIN users u ON u.id = p.parent_id
            ORDER BY p.created_at DESC";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $patients[] = $row;
        }
    }
    return $patients;
}

// PARENT-role users — needed when superadmin creates a child or appointment
// on behalf of an existing parent.
function get_parent_users($conn) {
    $rows = [];
    $res = mysqli_query($conn, "SELECT id, first_name, last_name, email FROM users WHERE user_type = 'PARENT' AND status = 'active' ORDER BY last_name, first_name");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; }
    }
    return $rows;
}

// Active doctors — used by appointment + schedule modals.
function get_active_doctors($conn) {
    $rows = [];
    $res = mysqli_query($conn, "SELECT id, first_name, last_name, specialization FROM users WHERE user_type IN ('DOCTOR','DOCTOR_OWNER') AND status = 'active' ORDER BY last_name, first_name");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; }
    }
    return $rows;
}

function get_announcements($conn) {
    $announcements = [];
    $query = "SELECT a.*, u.first_name, u.last_name
              FROM announcements a
              LEFT JOIN users u ON a.created_by = u.id
              ORDER BY a.created_at DESC";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $announcements[] = $row;
        }
    }
    return $announcements;
}

function get_all_appointments_for_calendar($conn) {
    $appointments = [];
    $query = "SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.type, a.duration,
              p.first_name as patient_first_name, p.last_name as patient_last_name,
              d.first_name as doctor_first_name, d.last_name as doctor_last_name
              FROM appointments a
              JOIN patients p ON a.patient_id = p.id
              JOIN users d ON a.doctor_id = d.id
              WHERE a.status NOT IN ('CANCELLED','NO_SHOW')
              ORDER BY a.appointment_date ASC, a.appointment_time ASC";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $appointments[] = $row;
        }
    }
    return $appointments;
}

function get_clinic_settings($conn) {
    $settings = [];
    $query = "SELECT * FROM clinic_settings";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $settings[$row['setting_key']] = $row;
        }
    }
    return $settings;
}

function get_activity_logs($conn, $limit = 50) {
    $logs = [];
    $limit = intval($limit);
    $query = "SELECT al.*, u.first_name, u.last_name
              FROM activity_logs al
              LEFT JOIN users u ON al.user_id = u.id
              ORDER BY al.created_at DESC
              LIMIT ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $logs[] = $row;
        }
    }
    return $logs;
}

// Helper functions for HTML rendering
function getRoleBadge($role) {
    switch ($role) {
        case 'PARENT': return 'role-parent';
        case 'DOCTOR':
        case 'DOCTOR_OWNER': return 'role-doctor';
        case 'ADMIN': return 'role-admin';
        case 'SUPERADMIN': return 'role-superadmin';
        default: return 'bg-gray-100 text-gray-800';
    }
}

function getAppointmentStatusBadge($status) {
    switch ($status) {
        case 'SCHEDULED': return 'status-scheduled';
        case 'CONFIRMED': return 'bg-blue-100 text-blue-800';
        case 'IN_PROGRESS': return 'bg-yellow-100 text-yellow-800';
        case 'COMPLETED': return 'status-completed';
        case 'CANCELLED': return 'status-cancelled';
        default: return 'bg-gray-100 text-gray-800';
    }
}

$stats = get_dashboard_stats($conn);
$recent_activity = get_recent_activity($conn);
$all_users = get_all_users($conn);
$doctor_schedules = get_doctor_schedules($conn);
$services = get_all_services($conn);
$appointments = get_all_appointments($conn);
$clinic_settings = get_clinic_settings($conn);
$activity_logs = get_activity_logs($conn);
$admin_announcements = get_announcements($conn);
$all_patients = $is_superadmin ? get_all_patients($conn) : [];
$parent_users = $is_superadmin ? get_parent_users($conn) : [];
$active_doctors_list = $is_superadmin ? get_active_doctors($conn) : [];

// ---- Chart data from database ----
// Appointments by type
$chart_appt_types = [];
$res = mysqli_query($conn, "SELECT type, COUNT(*) as cnt FROM appointments GROUP BY type");
if ($res) { while ($r = mysqli_fetch_assoc($res)) { $chart_appt_types[] = ['value' => (int)$r['cnt'], 'name' => $r['type']]; } }
if (empty($chart_appt_types)) { $chart_appt_types = [['value' => 0, 'name' => 'No Data']]; }

// Monthly appointments (current year)
$chart_monthly = array_fill(0, 12, 0);
$cur_year = date('Y');
$res = mysqli_query($conn, "SELECT MONTH(appointment_date) as m, COUNT(*) as cnt FROM appointments WHERE YEAR(appointment_date) = $cur_year GROUP BY MONTH(appointment_date)");
if ($res) { while ($r = mysqli_fetch_assoc($res)) { $chart_monthly[(int)$r['m'] - 1] = (int)$r['cnt']; } }

// Users distribution
$chart_users = [];
$res = mysqli_query($conn, "SELECT user_type, COUNT(*) as cnt FROM users GROUP BY user_type");
if ($res) { while ($r = mysqli_fetch_assoc($res)) { $chart_users[] = ['value' => (int)$r['cnt'], 'name' => $r['user_type']]; } }
if (empty($chart_users)) { $chart_users = [['value' => 0, 'name' => 'No Data']]; }

// Service appointment counts (for radar chart)
$chart_service_data = [];
$chart_service_labels = [];
$res = mysqli_query($conn, "SELECT type, COUNT(*) as cnt FROM appointments GROUP BY type ORDER BY cnt DESC LIMIT 5");
if ($res) { while ($r = mysqli_fetch_assoc($res)) { $chart_service_labels[] = $r['type']; $chart_service_data[] = (int)$r['cnt']; } }
if (empty($chart_service_labels)) { $chart_service_labels = ['No Data']; $chart_service_data = [0]; }
$chart_service_max = !empty($chart_service_data) ? max($chart_service_data) + 10 : 10;
$calendar_appointments = get_all_appointments_for_calendar($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - AlagApp Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="css/shared.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/admin.css" rel="stylesheet">
    <style>
        :root { --primary-pink: #ff7aa3; --light-pink: #FFBCD9; --dark-text: #333333; --light-gray: #F6F6F8; }
        body { font-family: 'Source Sans Pro', sans-serif; background: linear-gradient(135deg, #ffffff 0%, #fef5f8 100%); }
        .font-inter { font-family: 'Inter', sans-serif; }
        .text-primary { color: var(--primary-pink); }
        .bg-primary { background-color: var(--primary-pink); }
        .bg-light-pink { background-color: var(--light-pink); }
        .sidebar { background: linear-gradient(180deg, #ff7aa3 0%, #FFBCD9 100%); min-height: 100vh; }
        .card-hover { transition: all 0.3s ease; }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(255, 107, 154, 0.15); }
        .btn-primary { background: linear-gradient(135deg, var(--primary-pink), var(--light-pink)); transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(255, 107, 154, 0.3); }
        .modal-backdrop { backdrop-filter: blur(8px); background: rgba(0, 0, 0, 0.4); }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .status-scheduled { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #f3e8ff; color: #7c3aed; }
        .role-parent { background: #dbeafe; color: #1e40af; }
        .role-doctor { background: #d1fae5; color: #065f46; }
        .role-admin { background: #f3e8ff; color: #7c3aed; }
        .role-superadmin { background: #fee2e2; color: #b91c1c; }
        .data-table { max-height: 400px; overflow-y: auto; }
        .data-table::-webkit-scrollbar { width: 6px; }
        .data-table::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 3px; }
        .data-table::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 3px; }
        .data-table::-webkit-scrollbar-thumb:hover { background: #a1a1a1; }
        
        /* Notification styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 8px;
            color: white;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }
        .notification.show {
            transform: translateX(0);
        }
        .notification.success { background: #10B981; }
        .notification.error { background: #EF4444; }
        .notification.info { background: #3B82F6; }
    </style>
</head>
<body>
    <!-- Mobile Menu Button -->
    <button onclick="toggleAdminSidebar()" class="mobile-menu-btn fixed top-4 left-4 z-50 p-2 bg-white rounded-lg shadow-lg text-gray-700 hover:text-pink-500 transition-colors">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <!-- Mobile Overlay -->
    <div id="adminSidebarOverlay" class="fixed inset-0 bg-black/40 z-30 hidden" onclick="toggleAdminSidebar()"></div>

    <!-- Notification Container -->
    <div id="notification" class="notification hidden"></div>

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div id="adminSidebar" class="sidebar w-64 text-white">
            <div class="p-6">
                <h1 class="text-2xl font-inter font-bold mb-8">AlagApp</h1>
                
                <div class="mb-8">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="font-semibold" id="adminName">
                                <?php echo htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']); ?>
                            </div>
                            <div class="text-sm text-white/80">System Administrator</div>
                        </div>
                    </div>
                </div>
                
                <nav class="space-y-2">
                    <a href="#dashboard" onclick="showSection('dashboard')" class="flex items-center space-x-3 px-4 py-3 rounded-lg bg-white/20">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path></svg>
                        <span>Dashboard</span>
                    </a>
                    
                    <?php if ($is_superadmin): ?>
                    <a href="#users" onclick="showSection('users')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                        <span>User Management</span>
                    </a>
                    <a href="#patients" onclick="showSection('patients')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"></path></svg>
                        <span>Child / Patient Profiles</span>
                    </a>
                    <a href="#schedules" onclick="showSection('schedules')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>
                        <span>Doctor Schedules</span>
                    </a>
                    <?php endif; ?>

                    <a href="#services" onclick="showSection('services')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path></svg>
                        <span>Services & Vaccines</span>
                    </a>

                    <a href="#appointments" onclick="showSection('appointments')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z" clip-rule="evenodd"></path></svg>
                        <span>All Appointments</span>
                    </a>

                    <a href="#announcements" onclick="showSection('announcements')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 3a1 1 0 00-1.447-.894L8.763 6H5a3 3 0 000 6h.28l1.771 5.316A1 1 0 008 18h1a1 1 0 00.948-.684l1.581-4.737.914.457A1 1 0 0013 13V7a1 1 0 00-.553-.894L17 3.618V13a1 1 0 102 0V3z" clip-rule="evenodd"></path></svg>
                        <span>Announcements</span>
                    </a>

                    <a href="#settings" onclick="showSection('settings')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"></path></svg>
                        <span>System Settings</span>
                    </a>
                    
                    <a href="#logs" onclick="showSection('logs')" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z" clip-rule="evenodd"></path></svg>
                        <span>Audit Logs</span>
                    </a>
                </nav>

                <div class="mt-8 pt-8 border-t border-white/20">
                    <a href="logout.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 001 1h12a1 1 0 001-1V4a1 1 0 00-1-1H3zm10.293 9.707a1 1 0 001.414-1.414l-3-3a1 1 0 00-1.414 0l-3 3a1 1 0 101.414 1.414L9 10.414V16a1 1 0 102 0v-5.586l1.293 1.293z" clip-rule="evenodd"></path>
                        </svg>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content flex-1 overflow-auto">
            <!-- Top bar: notification bell -->
            <div class="flex items-center justify-end gap-2 px-8 pt-4">
                <div id="notificationBellMount"></div>
            </div>
            <!-- Dashboard Section -->
            <div id="dashboard-section" class="section-content p-8">
                <div class="mb-8">
                    <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Admin Dashboard</h1>
                    <p class="text-gray-600">System overview and management</p>
                </div>
                
                <!-- System Overview Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-6 mb-8">
                    <div class="card-hover bg-white rounded-xl shadow-lg p-6" <?php echo $is_superadmin ? "onclick=\"showSection('users')\"" : ''; ?>>
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100">
                                <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-2xl font-bold text-gray-800"><?php echo $stats['total_users']; ?></div>
                                <div class="text-sm text-gray-600">Total Users</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-hover bg-white rounded-xl shadow-lg p-6" onclick = "showSection('appointments')">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-orange-100">
                                <svg class="w-8 h-8 text-orange-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path></svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-2xl font-bold text-gray-800"><?php echo $stats['total_appointments']; ?></div>
                                <div class="text-sm text-gray-600">Total Appointments</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Charts -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="card-hover bg-white rounded-xl shadow-lg p-6 cursor-pointer hover:ring-2 hover:ring-primary/40 transition"
                         onclick="showSection('appointments')" title="View appointments">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-inter font-semibold text-gray-800">Appointments by Type</h3>
                            <span class="text-xs text-primary font-medium">View all →</span>
                        </div>
                        <div id="appointmentsChart" style="width:100%;height:250px;"></div>
                    </div>
                    <div class="card-hover bg-white rounded-xl shadow-lg p-6 cursor-pointer hover:ring-2 hover:ring-primary/40 transition"
                         onclick="showSection('appointments')" title="View appointments">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-inter font-semibold text-gray-800">Monthly Appointments</h3>
                            <span class="text-xs text-primary font-medium">View all →</span>
                        </div>
                        <div id="monthlyChart" style="width:100%;height:250px;"></div>
                    </div>
                    <?php if ($is_superadmin): ?>
                    <div class="card-hover bg-white rounded-xl shadow-lg p-6 cursor-pointer hover:ring-2 hover:ring-primary/40 transition"
                         onclick="showSection('users')" title="View users">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-inter font-semibold text-gray-800">Users Distribution</h3>
                            <span class="text-xs text-primary font-medium">View users →</span>
                        </div>
                        <div id="usersChart" style="width:100%;height:250px;"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Printable Reports -->
                <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h3 class="text-lg font-inter font-semibold text-gray-800">Printable Reports</h3>
                            <p class="text-sm text-gray-500">Generate printer-friendly reports for your records</p>
                        </div>
                        <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <button type="button" onclick="printReport('users')" class="group text-left p-4 border border-pink-100 hover:border-primary hover:bg-pink-50 rounded-lg transition">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 rounded-lg bg-pink-100 text-primary flex items-center justify-center">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-5.13a4 4 0 11-8 0 4 4 0 018 0zm6 3a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </div>
                                <span class="font-semibold text-gray-800 group-hover:text-primary">All Users</span>
                            </div>
                            <p class="text-xs text-gray-500">Full list of parents, doctors, and admins</p>
                        </button>
                        <button type="button" onclick="printReport('services')" class="group text-left p-4 border border-pink-100 hover:border-primary hover:bg-pink-50 rounded-lg transition">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 rounded-lg bg-pink-100 text-primary flex items-center justify-center">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M5 6h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/></svg>
                                </div>
                                <span class="font-semibold text-gray-800 group-hover:text-primary">Clinic Services</span>
                            </div>
                            <p class="text-xs text-gray-500">All clinic services with duration &amp; cost</p>
                        </button>
                        <button type="button" onclick="printReport('appointments')" class="group text-left p-4 border border-pink-100 hover:border-primary hover:bg-pink-50 rounded-lg transition">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 rounded-lg bg-pink-100 text-primary flex items-center justify-center">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                </div>
                                <span class="font-semibold text-gray-800 group-hover:text-primary">All Appointments</span>
                            </div>
                            <p class="text-xs text-gray-500">Full appointments list with status</p>
                        </button>
                        <button type="button" onclick="printReport('logs')" class="group text-left p-4 border border-pink-100 hover:border-primary hover:bg-pink-50 rounded-lg transition">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 rounded-lg bg-pink-100 text-primary flex items-center justify-center">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </div>
                                <span class="font-semibold text-gray-800 group-hover:text-primary">Audit Logs</span>
                            </div>
                            <p class="text-xs text-gray-500">Recent activity and user actions</p>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- User Management Section (Super Admin only) -->
            <?php if ($is_superadmin): ?>
            <div id="users-section" class="section-content p-8 hidden">
                <div class="mb-8">
                    <div class="flex justify-between items-center">
                        <div>
                            <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">User Management</h1>
                            <p class="text-gray-600">Manage system users and permissions</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="printReport('users')" class="px-4 py-3 border border-primary text-primary rounded-lg font-semibold hover:bg-pink-50 flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                Print
                            </button>
                            <button onclick="openAddUserModal()" class="btn-primary text-white px-6 py-3 rounded-lg font-semibold">
                                Add User
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-inter font-semibold text-gray-800">All Users</h3>
                        <div class="flex space-x-4">
                            <select id="userRoleFilter" onchange="filterUsers()" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">All Roles</option>
                                <option value="PARENT">Parents</option>
                                <option value="DOCTOR">Doctors</option>
                                <option value="ADMIN">Admins</option>
                                <option value="SUPERADMIN">Super Admins</option>
                            </select>
                            <select id="userStatusFilter" onchange="filterUsers()" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="data-table">
                        <table class="w-full">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody" class="bg-white divide-y divide-gray-200">
                                <?php foreach ($all_users as $user):
                                    $u_role = strtoupper($user['user_type']);
                                    $isDoctor = in_array($u_role, ['DOCTOR', 'DOCTOR_OWNER'], true);
                                    $isSelf = (int) $user['id'] === (int) ($_SESSION['user_id'] ?? 0);
                                    $userJson = htmlspecialchars(
                                        json_encode([
                                            'id' => (int) $user['id'],
                                            'first_name' => $user['first_name'] ?? '',
                                            'last_name' => $user['last_name'] ?? '',
                                            'email' => $user['email'] ?? '',
                                            'phone' => $user['phone'] ?? '',
                                            'user_type' => $u_role,
                                            'status' => $user['status'] ?? '',
                                            'date_of_birth' => $user['date_of_birth'] ?? '',
                                            'gender' => $user['gender'] ?? '',
                                            'address' => $user['address'] ?? '',
                                            'emergency_contact_name' => $user['emergency_contact_name'] ?? '',
                                            'emergency_contact_phone' => $user['emergency_contact_phone'] ?? '',
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP),
                                        ENT_QUOTES, 'UTF-8'
                                    );
                                ?>
                                <tr class="hover:bg-gray-50"
                                    data-user-id="<?php echo (int) $user['id']; ?>"
                                    data-role="<?php echo htmlspecialchars($u_role); ?>"
                                    data-status="<?php echo htmlspecialchars(strtolower($user['status'])); ?>"
                                    data-user='<?php echo $userJson; ?>'>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?><?php echo $isSelf ? ' <span class="text-xs text-gray-400">(you)</span>' : ''; ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($user['email']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($isDoctor || $isSelf): ?>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo getRoleBadge($user['user_type']); ?>"><?php echo $user['user_type']; ?></span>
                                            <?php if ($isDoctor): ?><span class="ml-1 text-xs text-gray-400" title="Doctor role is locked">&#128274;</span><?php endif; ?>
                                        <?php else: ?>
                                            <select class="px-2 py-1 text-xs rounded border border-gray-300 focus:ring-1 focus:ring-primary focus:outline-none"
                                                    data-current-role="<?php echo htmlspecialchars($u_role); ?>"
                                                    onchange="changeUserRoleSelect(<?php echo (int) $user['id']; ?>, this)">
                                                <?php foreach (['PARENT', 'ADMIN', 'SUPERADMIN'] as $r): ?>
                                                    <option value="<?php echo $r; ?>" <?php echo $u_role === $r ? 'selected' : ''; ?>><?php echo $r; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $user['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <?php if ($isSelf): ?>
                                            <span class="text-xs text-gray-400" title="You can't modify your own account">No actions</span>
                                        <?php else: ?>
                                            <button onclick="editUser(<?php echo $user['id']; ?>)" class="text-primary hover:underline mr-3">Edit</button>
                                            <button onclick="toggleUserStatus(<?php echo $user['id']; ?>)" class="text-<?php echo $user['status'] === 'active' ? 'red' : 'green'; ?>-600 hover:underline">
                                                <?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>


            <!-- Services & Vaccines Section -->
            <div id="services-section" class="section-content p-8 hidden">
                <div class="mb-8">
                    <div class="flex justify-between items-center">
                        <div>
                            <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Services & Vaccines</h1>
                            <p class="text-gray-600">Manage clinic services and vaccination offerings</p>
                        </div>
                        <div class="flex space-x-2">
                            <button type="button" onclick="printReport('services')" class="px-4 py-3 border border-primary text-primary rounded-lg font-semibold hover:bg-pink-50 flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                Print
                            </button>
                            <button onclick="openAddServiceModal()" class="btn-primary text-white px-6 py-3 rounded-lg font-semibold">
                                Add Service
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-inter font-semibold text-gray-800 mb-6">Clinic Services</h3>
                    
                    <div class="data-table">
                        <table class="w-full">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cost</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($services as $service): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($service['name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($service['description']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo $service['duration']; ?> mins</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">&#8369;<?php echo number_format($service['cost'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $service['active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $service['active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button onclick="editService(<?php echo $service['id']; ?>)" class="text-primary hover:underline mr-3">Edit</button>
                                        <button onclick="toggleServiceStatus(<?php echo $service['id']; ?>)" class="text-<?php echo $service['active'] ? 'red' : 'green'; ?>-600 hover:underline">
                                            <?php echo $service['active'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- All Appointments Section -->
            <div id="appointments-section" class="section-content p-8 hidden">
                <div class="mb-8">
                    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4">
                        <div>
                            <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">All Appointments</h1>
                            <p class="text-gray-600">View and manage all clinic appointments</p>
                        </div>
                        <div class="flex flex-wrap gap-3 items-end">
                            <?php if ($is_superadmin): ?>
                            <button type="button" onclick="openCreateAppointmentModal()" class="px-3 py-2 bg-primary text-white rounded-lg text-sm font-semibold hover:opacity-90 flex items-center gap-1" style="height:fit-content;align-self:end">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                New Appointment
                            </button>
                            <?php endif; ?>
                            <button type="button" onclick="printReport('appointments')" class="px-3 py-2 bg-primary text-white rounded-lg text-sm font-semibold hover:opacity-90 flex items-center gap-1" style="height:fit-content;align-self:end">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                Print
                            </button>
                            <div>
                                <label for="appointmentSearch" class="block text-xs font-medium text-gray-500 mb-1">Search</label>
                                <input type="text" id="appointmentSearch" oninput="filterAppointments()" placeholder="Patient, doctor, reason…" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                            </div>
                            <div>
                                <label for="appointmentStatusFilter" class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                                <select id="appointmentStatusFilter" onchange="filterAppointments()" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                                    <option value="">All Status</option>
                                    <option value="SCHEDULED">Scheduled</option>
                                    <option value="CONFIRMED">Confirmed</option>
                                    <option value="IN_PROGRESS">In Progress</option>
                                    <option value="COMPLETED">Completed</option>
                                    <option value="CANCELLED">Cancelled</option>
                                </select>
                            </div>
                            <div>
                                <label for="appointmentDateFrom" class="block text-xs font-medium text-gray-500 mb-1">From</label>
                                <input type="date" id="appointmentDateFrom" onchange="filterAppointments()" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                            </div>
                            <div>
                                <label for="appointmentDateTo" class="block text-xs font-medium text-gray-500 mb-1">To</label>
                                <input type="date" id="appointmentDateTo" onchange="filterAppointments()" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                            </div>
                            <button type="button" onclick="clearAppointmentFilters()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear</button>
                        </div>
                    </div>
                </div>
                
                <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                    <div class="data-table">
                        <table class="w-full">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patient</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Doctor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="appointmentsTableBody">
                                <?php foreach ($appointments as $appointment): ?>
                                <tr class="hover:bg-gray-50 js-appointment-row transition-all"
                                    data-appointment-id="<?php echo (int)$appointment['id']; ?>"
                                    data-status="<?php echo htmlspecialchars(strtoupper($appointment['status']), ENT_QUOTES); ?>"
                                    data-date="<?php echo htmlspecialchars($appointment['appointment_date'], ENT_QUOTES); ?>"
                                    data-search="<?php echo htmlspecialchars(strtolower(trim(
                                        ($appointment['patient_first_name'] ?? '') . ' ' .
                                        ($appointment['patient_last_name']  ?? '') . ' ' .
                                        ($appointment['doctor_first_name']  ?? '') . ' ' .
                                        ($appointment['doctor_last_name']   ?? '') . ' ' .
                                        ($appointment['reason']             ?? '')
                                    )), ENT_QUOTES); ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($appointment['patient_first_name'] . ' ' . $appointment['patient_last_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">Dr. <?php echo htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($appointment['appointment_date'])); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800"><?php echo $appointment['type']; ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo getAppointmentStatusBadge($appointment['status']); ?>"><?php echo str_replace('_', ' ', $appointment['status']); ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($appointment['reason']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button onclick="editAppointment(<?php echo $appointment['id']; ?>)" class="text-primary hover:underline mr-3">Edit</button>
                                        <button onclick="updateAppointmentStatus(<?php echo $appointment['id']; ?>)" class="text-green-600 hover:underline">Update Status</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="noAppointmentsRow" class="hidden">
                                    <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">No appointments match the current filters.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            

            <?php if ($is_superadmin): ?>
            <!-- Child / Patient Profiles Section (SuperAdmin) -->
            <div id="patients-section" class="section-content p-8 hidden">
                <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Child / Patient Profiles</h1>
                        <p class="text-gray-600">Add or review all registered children in the clinic.</p>
                    </div>
                    <button onclick="openAddPatientModal()" class="btn-primary text-white px-6 py-3 rounded-lg font-semibold">
                        + Add Child Profile
                    </button>
                </div>
                <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                    <div class="data-table">
                        <table class="w-full">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date of Birth</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gender</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Parent / Guardian</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Allergies</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Added</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($all_patients)): ?>
                                    <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">No child profiles yet.</td></tr>
                                <?php else: foreach ($all_patients as $p): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                            <?php echo $p['date_of_birth'] ? date('M j, Y', strtotime($p['date_of_birth'])) : '—'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($p['gender'] ?? '—'); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($p['parent_first_name'] . ' ' . $p['parent_last_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($p['parent_email']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($p['allergies'] ?: '—'); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('M j, Y', strtotime($p['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Doctor Schedules Section (SuperAdmin) -->
            <div id="schedules-section" class="section-content p-8 hidden">
                <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Doctor Schedules</h1>
                        <p class="text-gray-600">Create and manage weekly working hours for doctors.</p>
                    </div>
                    <button onclick="openAddScheduleModal()" class="btn-primary text-white px-6 py-3 rounded-lg font-semibold">
                        + Add Schedule
                    </button>
                </div>
                <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                    <div class="data-table">
                        <table class="w-full">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Doctor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slot</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Max Patients</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($doctor_schedules)): ?>
                                    <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">No doctor schedules yet.</td></tr>
                                <?php else: foreach ($doctor_schedules as $s): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">Dr. <?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo ucfirst(strtolower($s['day_of_week'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                            <?php echo date('g:i A', strtotime($s['start_time'])); ?> – <?php echo date('g:i A', strtotime($s['end_time'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo intval($s['slot_duration']); ?> min</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo intval($s['max_patients'] ?? 0); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button onclick="deleteDoctorSchedule(<?php echo (int) $s['id']; ?>)" class="text-red-600 hover:underline">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Announcements Section -->
            <div id="announcements-section" class="section-content p-4 md:p-8 hidden">
                <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Announcements</h1>
                        <p class="text-gray-600 text-sm md:text-base">Manage clinic announcements visible on the landing page</p>
                    </div>
                    <button onclick="openAnnouncementFormModal()" class="btn-primary text-white px-4 md:px-6 py-2 md:py-3 rounded-lg font-semibold text-sm md:text-base">
                        + New Announcement
                    </button>
                </div>

                <!-- Announcements List -->
                <div class="bg-white rounded-xl shadow-lg p-4 md:p-6">
                    <div class="space-y-4">
                        <?php if (empty($admin_announcements)): ?>
                            <div class="text-center py-12">
                                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                                <p class="text-gray-500 text-lg">No announcements yet</p>
                                <p class="text-gray-400 text-sm mt-1">Create your first announcement to display on the landing page</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($admin_announcements as $ann): ?>
                                <div class="border border-gray-200 rounded-lg p-4 md:p-6 hover:bg-gray-50 transition-colors">
                                    <div class="flex flex-col sm:flex-row justify-between items-start gap-3">
                                        <div class="flex-1">
                                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($ann['title']); ?></h3>
                                                <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php
                                                    echo $ann['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600';
                                                ?>"><?php echo $ann['is_active'] ? 'Active' : 'Inactive'; ?></span>
                                                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars($ann['category']); ?></span>
                                                <?php if ($ann['priority'] === 'HIGH' || $ann['priority'] === 'URGENT'): ?>
                                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-800"><?php echo htmlspecialchars($ann['priority']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-gray-600 text-sm line-clamp-2"><?php echo htmlspecialchars(substr(strip_tags($ann['content']), 0, 200)); ?></p>
                                            <div class="text-xs text-gray-400 mt-2">
                                                By <?php echo htmlspecialchars(($ann['first_name'] ?? '') . ' ' . ($ann['last_name'] ?? '')); ?>
                                                &bull; <?php echo date('M j, Y g:i A', strtotime($ann['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button onclick="toggleAnnouncementStatus(<?php echo $ann['id']; ?>, <?php echo $ann['is_active'] ? '0' : '1'; ?>)"
                                                    class="px-3 py-1.5 text-xs font-medium rounded-lg <?php echo $ann['is_active'] ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-green-100 text-green-700 hover:bg-green-200'; ?> transition-colors">
                                                <?php echo $ann['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                            <button onclick="deleteAnnouncement(<?php echo $ann['id']; ?>)"
                                                    class="px-3 py-1.5 text-xs font-medium rounded-lg bg-red-100 text-red-700 hover:bg-red-200 transition-colors">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Add Announcement Modal -->
            <div id="addAnnouncementModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-xl max-h-[90vh] overflow-y-auto">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h3 class="text-xl font-semibold text-gray-800">New Announcement</h3>
                            <button onclick="closeAnnouncementFormModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
                        </div>
                    </div>
                    <div class="p-6">
                        <form id="addAnnouncementForm" onsubmit="handleAddAnnouncement(event)">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                                <input type="text" id="annTitle" required maxlength="200" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-300 focus:border-transparent text-sm" placeholder="Announcement title...">
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                                <textarea id="annContent" required rows="5" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-300 focus:border-transparent text-sm" placeholder="Write your announcement..."></textarea>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                                    <select id="annCategory" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-300 text-sm">
                                        <option value="GENERAL">General</option>
                                        <option value="MAINTENANCE">Maintenance</option>
                                        <option value="HEALTH_ADVISORY">Health Advisory</option>
                                        <option value="EVENT">Event</option>
                                        <option value="PROMOTION">Promotion</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                                    <select id="annPriority" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-300 text-sm">
                                        <option value="LOW">Low</option>
                                        <option value="NORMAL" selected>Normal</option>
                                        <option value="HIGH">High</option>
                                        <option value="URGENT">Urgent</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="closeAnnouncementFormModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">Cancel</button>
                                <button type="submit" class="px-4 py-2 text-sm font-medium text-white rounded-lg btn-primary">Publish Announcement</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- System Settings Section -->
            <div id="settings-section" class="section-content p-8 hidden">
                <div class="mb-8">
                    <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">System Settings</h1>
                    <p class="text-gray-600">Configure clinic settings and preferences</p>
                </div>
                
                <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-inter font-semibold text-gray-800 mb-6">Clinic Information</h3>
                    
                    <form id="clinicSettingsForm" onsubmit="updateClinicSettings(event)" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Clinic Name</label>
                                <input type="text" id="clinic_name" value="<?php echo htmlspecialchars($clinic_settings['clinic_name']['setting_value'] ?? ''); ?>" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                                <input type="text" id="clinic_phone" value="<?php echo htmlspecialchars($clinic_settings['clinic_phone']['setting_value'] ?? ''); ?>" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                                <input type="email" id="clinic_email" value="<?php echo htmlspecialchars($clinic_settings['clinic_email']['setting_value'] ?? ''); ?>" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Appointment Reminder (hours)</label>
                                <input type="number" id="appointment_reminder_hours" value="<?php echo htmlspecialchars($clinic_settings['appointment_reminder_hours']['setting_value'] ?? '24'); ?>" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Clinic Address</label>
                            <textarea id="clinic_address" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"><?php echo htmlspecialchars($clinic_settings['clinic_address']['setting_value'] ?? ''); ?></textarea>
                        </div>
                        
                        <hr class="my-4 border-pink-100">
                        <h4 class="text-lg font-inter font-semibold text-gray-800">Landing Page — "Get In Touch"</h4>
                        <p class="text-sm text-gray-500 -mt-2">Shown in the footer of the public landing page.</p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Contact Address</label>
                                <input type="text" id="contact_address" value="<?php echo htmlspecialchars($clinic_settings['contact_address']['setting_value'] ?? 'Manila, Philippines'); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Contact Phone</label>
                                <input type="text" id="contact_phone" value="<?php echo htmlspecialchars($clinic_settings['contact_phone']['setting_value'] ?? '+63 (2) 1234 5678'); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Contact Email</label>
                                <input type="email" id="contact_email" value="<?php echo htmlspecialchars($clinic_settings['contact_email']['setting_value'] ?? 'hello@alagapp.clinic'); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Opening Hours</label>
                                <input type="text" id="contact_hours" value="<?php echo htmlspecialchars($clinic_settings['contact_hours']['setting_value'] ?? 'Mon – Sat: 8:00 AM – 6:00 PM'); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="btn-primary text-white px-8 py-3 rounded-lg font-semibold">
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Audit Logs Section -->
            <div id="logs-section" class="section-content p-8 hidden">
                <div class="mb-8 flex flex-col md:flex-row md:justify-between md:items-center gap-3">
                    <div>
                        <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Audit Logs</h1>
                        <p class="text-gray-600">System activity and user actions</p>
                    </div>
                    <button type="button" onclick="printReport('logs')" class="self-start md:self-auto px-4 py-3 bg-primary text-white rounded-lg font-semibold hover:opacity-90 flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Print Audit Logs
                    </button>
                </div>
                
                <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-inter font-semibold text-gray-800">Activity Logs</h3>
                        <div class="flex space-x-4">
                            <input type="date" id="logDateFilter" onchange="filterLogs()" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <select id="logActionFilter" onchange="filterLogs()" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">All Actions</option>
                                <option value="LOGIN">Login</option>
                                <option value="LOGOUT">Logout</option>
                                <option value="CREATE">Create</option>
                                <option value="UPDATE">Update</option>
                                <option value="DELETE">Delete</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="data-table">
                        <table class="w-full">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($activity_logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars(($log['first_name'] ?? 'Unknown') . ' ' . ($log['last_name'] ?? 'User')); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800"><?php echo $log['action']; ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($log['details'] ?? 'No details'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal (SuperAdmin) -->
    <div id="editUserModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6 md:p-8">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h2 class="text-2xl font-inter font-bold text-gray-800 mb-1">Edit User</h2>
                        <p class="text-gray-600 text-sm">Update this user's profile and account settings.</p>
                    </div>
                    <button type="button" onclick="closeEditUserModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <form id="editUserForm" onsubmit="handleEditUser(event)" class="space-y-4">
                    <input type="hidden" id="editUserId" name="user_id">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                            <input type="text" id="editUserFirstName" required maxlength="50" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                            <input type="text" id="editUserLastName" required maxlength="50" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" id="editUserEmail" required maxlength="100" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input type="tel" id="editUserPhone" maxlength="20" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                            <input type="date" id="editUserDob" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                            <select id="editUserGender" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">Unspecified</option>
                                <option value="MALE">Male</option>
                                <option value="FEMALE">Female</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea id="editUserAddress" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                            <input type="text" id="editUserEmergencyName" maxlength="100" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Phone</label>
                            <input type="tel" id="editUserEmergencyPhone" maxlength="20" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                            <select id="editUserRole" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="PARENT">Parent</option>
                                <option value="ADMIN">Admin</option>
                                <option value="SUPERADMIN">Super Admin</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500" id="editUserRoleHint">Doctor roles cannot be changed here.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="editUserStatus" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
                        <button type="button" onclick="closeEditUserModal()" class="px-4 py-2 border border-gray-300 rounded-lg font-medium hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 btn-primary text-white rounded-lg font-semibold">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-8">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-inter font-bold text-gray-800 mb-2">Add New User</h2>
                    <p class="text-gray-600">Create a new system user account</p>
                </div>
                
                <form id="addUserForm" onsubmit="handleAddUser(event)" class="space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                            <input type="text" id="newUserFirstName" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                            <input type="text" id="newUserLastName" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" id="newUserEmail" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                        <input type="tel" id="newUserPhone" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">User Role</label>
                        <select id="newUserRole" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Select role</option>
                            <option value="PARENT">Parent/Guardian</option>
                            <option value="DOCTOR">Doctor</option>
                            <option value="ADMIN">Administrator</option>
                            <option value="SUPERADMIN">Super Administrator</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Initial Password</label>
                        <input type="password" id="newUserPassword" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>
                    
                    <div class="flex space-x-4">
                        <button type="submit" class="flex-1 btn-primary text-white py-3 rounded-lg font-semibold">
                            Create User
                        </button>
                        <button type="button" onclick="closeAddUserModal()" 
                                class="flex-1 border border-gray-300 text-gray-700 py-3 rounded-lg font-semibold hover:bg-gray-50">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Schedule Modal -->
    <div id="addScheduleModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-8">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-inter font-bold text-gray-800 mb-2">Add Schedule</h2>
                    <p class="text-gray-600">Add new doctor schedule</p>
                </div>
                
                <form id="addScheduleForm" onsubmit="handleAddSchedule(event)" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Doctor</label>
                        <select id="scheduleDoctor" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Select doctor</option>
                            <?php
                            $doctors = get_all_doctors($conn);
                            foreach ($doctors as $doc) {
                                echo '<option value="' . intval($doc['id']) . '">' . htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Day of Week</label>
                        <select id="scheduleDay" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">Select day</option>
                            <option value="MONDAY">Monday</option>
                            <option value="TUESDAY">Tuesday</option>
                            <option value="WEDNESDAY">Wednesday</option>
                            <option value="THURSDAY">Thursday</option>
                            <option value="FRIDAY">Friday</option>
                            <option value="SATURDAY">Saturday</option>
                            <option value="SUNDAY">Sunday</option>
                        </select>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Start Time</label>
                            <input type="time" id="scheduleStartTime" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">End Time</label>
                            <input type="time" id="scheduleEndTime" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Slot Duration (mins)</label>
                            <input type="number" id="scheduleDuration" value="30" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Max Patients</label>
                            <input type="number" id="scheduleMaxPatients" value="10" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="flex space-x-4">
                        <button type="submit" class="flex-1 btn-primary text-white py-3 rounded-lg font-semibold">
                            Add Schedule
                        </button>
                        <button type="button" onclick="closeAddScheduleModal()" 
                                class="flex-1 border border-gray-300 text-gray-700 py-3 rounded-lg font-semibold hover:bg-gray-50">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div id="addServiceModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-8">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-inter font-bold text-gray-800 mb-2">Add Service</h2>
                    <p class="text-gray-600">Add new clinic service</p>
                </div>
                
                <form id="addServiceForm" onsubmit="handleAddService(event)" class="space-y-6" novalidate>
                    <div>
                        <label for="serviceName" class="block text-sm font-medium text-gray-700 mb-2">Service Name</label>
                        <input type="text" id="serviceName" name="name" required minlength="2" maxlength="100"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div>
                        <label for="serviceDescription" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea id="serviceDescription" name="description" rows="3" required minlength="10" maxlength="500"
                                  placeholder="Describe the service (at least 10 characters)"
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="serviceDuration" class="block text-sm font-medium text-gray-700 mb-2">Duration (mins)</label>
                            <input type="number" id="serviceDuration" name="duration" value="30" required min="1" max="480" step="1" inputmode="numeric"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label for="serviceCost" class="block text-sm font-medium text-gray-700 mb-2">Cost (&#8369;)</label>
                            <input type="number" id="serviceCost" name="cost" step="0.01" min="0" required inputmode="decimal"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>

                    <div class="flex space-x-4">
                        <button type="submit" class="flex-1 btn-primary text-white py-3 rounded-lg font-semibold">
                            Add Service
                        </button>
                        <button type="button" onclick="closeAddServiceModal()"
                                class="flex-1 border border-gray-300 text-gray-700 py-3 rounded-lg font-semibold hover:bg-gray-50">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($is_superadmin): ?>
    <!-- Add Child Profile Modal (SuperAdmin) -->
    <div id="addPatientModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6 md:p-8">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h2 class="text-2xl font-inter font-bold text-gray-800 mb-1">Add Child Profile</h2>
                        <p class="text-sm text-gray-600">Create a new child/patient record linked to a parent account.</p>
                    </div>
                    <button type="button" onclick="closeAddPatientModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <form id="addPatientForm" onsubmit="handleAddPatient(event)" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Parent / Guardian</label>
                        <select id="patientParentId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">-- Select parent --</option>
                            <?php foreach ($parent_users as $pu): ?>
                                <option value="<?php echo (int) $pu['id']; ?>">
                                    <?php echo htmlspecialchars($pu['last_name'] . ', ' . $pu['first_name'] . ' — ' . $pu['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                            <input type="text" id="patientFirstName" required maxlength="50" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                            <input type="text" id="patientLastName" required maxlength="50" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                            <input type="date" id="patientDob" required max="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                            <select id="patientGender" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">-- Select --</option>
                                <option value="MALE">Male</option>
                                <option value="FEMALE">Female</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Blood Type</label>
                            <select id="patientBloodType" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">Unknown</option>
                                <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                                    <option value="<?php echo $bt; ?>"><?php echo $bt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Height (cm)</label>
                            <input type="number" step="0.1" min="0" max="250" id="patientHeight" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Weight (kg)</label>
                            <input type="number" step="0.1" min="0" max="300" id="patientWeight" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Allergies</label>
                        <textarea id="patientAllergies" rows="2" maxlength="1000" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Known allergies, if any"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Medical Conditions</label>
                        <textarea id="patientConditions" rows="2" maxlength="1000" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Chronic conditions, congenital, etc."></textarea>
                    </div>
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
                        <button type="button" onclick="closeAddPatientModal()" class="px-4 py-2 border border-gray-300 rounded-lg font-medium hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 btn-primary text-white rounded-lg font-semibold">Save Profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Appointment Modal (SuperAdmin) -->
    <div id="createAppointmentModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6 md:p-8">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h2 class="text-2xl font-inter font-bold text-gray-800 mb-1">Create Appointment</h2>
                        <p class="text-sm text-gray-600">Schedule an appointment for any patient with any doctor.</p>
                    </div>
                    <button type="button" onclick="closeCreateAppointmentModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <form id="createAppointmentForm" onsubmit="handleCreateAppointment(event)" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Patient</label>
                        <select id="apptPatientId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">-- Select patient --</option>
                            <?php foreach ($all_patients as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name'] . ' (' . $p['parent_first_name'] . ' ' . $p['parent_last_name'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Doctor</label>
                        <select id="apptDoctorId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="">-- Select doctor --</option>
                            <?php foreach ($active_doctors_list as $doc): ?>
                                <option value="<?php echo (int) $doc['id']; ?>">
                                    Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?>
                                    <?php if (!empty($doc['specialization'])) echo ' — ' . htmlspecialchars($doc['specialization']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input type="date" id="apptDate" required min="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Time</label>
                            <input type="time" id="apptTime" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <select id="apptType" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="CONSULTATION">Consultation</option>
                            <option value="VACCINATION">Vaccination</option>
                            <option value="CHECKUP">Checkup</option>
                            <option value="FOLLOW_UP">Follow-up</option>
                            <option value="EMERGENCY">Emergency</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reason / Notes</label>
                        <textarea id="apptReason" rows="3" maxlength="1000" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Optional notes or reason for the visit"></textarea>
                    </div>
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
                        <button type="button" onclick="closeCreateAppointmentModal()" class="px-4 py-2 border border-gray-300 rounded-lg font-medium hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 btn-primary text-white rounded-lg font-semibold">Create Appointment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    window.CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
    window.adminCalendarAppointments = <?php echo json_encode($calendar_appointments); ?>;
    </script>
    <script src="js/shared-toast.js"></script>
    <script src="js/admin-dashboard-calendar.js"></script>
    <script src="js/admin-dashboard.js"></script>
    <script src="js/notification-bell.js"></script>
    <script>
    // Bridge: pass PHP data to external JS - chart init and form handlers with CSRF
    document.addEventListener('DOMContentLoaded', function() {
        initializeAdminDashboard();
        initializeCharts();
    });

    function initializeAdminDashboard() {
        // Dashboard is already loaded with PHP data
    }

    function initializeCharts() {
        var chartApptTypes = <?php echo json_encode($chart_appt_types); ?>;
        var chartMonthly = <?php echo json_encode($chart_monthly); ?>;
        var chartUsers = <?php echo json_encode($chart_users); ?>;
        var chartServiceLabels = <?php echo json_encode($chart_service_labels); ?>;
        var chartServiceData = <?php echo json_encode($chart_service_data); ?>;
        var chartServiceMax = <?php echo json_encode($chart_service_max); ?>;

        var appointmentsChart = echarts.init(document.getElementById('appointmentsChart'));
        appointmentsChart.setOption({
            tooltip: { trigger: 'item' },
            legend: { orient: 'vertical', right: 10, top: 'center' },
            series: [{ name: 'Appointments', type: 'pie', radius: ['40%', '70%'],
                data: chartApptTypes,
                emphasis: { itemStyle: { shadowBlur: 10, shadowOffsetX: 0, shadowColor: 'rgba(0, 0, 0, 0.5)' } }
            }]
        });

        var monthlyChart = echarts.init(document.getElementById('monthlyChart'));
        monthlyChart.setOption({
            tooltip: { trigger: 'axis' },
            xAxis: { type: 'category', data: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] },
            yAxis: { type: 'value' },
            series: [{ data: chartMonthly, type: 'bar', itemStyle: { color: '#ff7aa3' } }]
        });

        var usersChart = echarts.init(document.getElementById('usersChart'));
        usersChart.setOption({
            tooltip: { trigger: 'item' },
            series: [{ name: 'Users', type: 'pie', radius: '70%',
                data: chartUsers,
                emphasis: { itemStyle: { shadowBlur: 10, shadowOffsetX: 0, shadowColor: 'rgba(0, 0, 0, 0.5)' } }
            }]
        });

        var serviceIndicators = chartServiceLabels.map(function(label) {
            return { name: label, max: chartServiceMax };
        });
        var revenueChart = echarts.init(document.getElementById('revenueChart'));
        revenueChart.setOption({
            tooltip: { trigger: 'axis' },
            radar: { indicator: serviceIndicators },
            series: [{ type: 'radar', data: [{ value: chartServiceData, name: 'Appointments' }] }]
        });

        window.addEventListener('resize', function() {
            appointmentsChart.resize();
            monthlyChart.resize();
            usersChart.resize();
            revenueChart.resize();
        });
    }

    function handleAddUser(event) {
        event.preventDefault();
        var firstName = document.getElementById('newUserFirstName').value.trim();
        var lastName = document.getElementById('newUserLastName').value.trim();
        var email = document.getElementById('newUserEmail').value.trim();
        var phone = document.getElementById('newUserPhone').value.trim();
        var role = document.getElementById('newUserRole').value;
        var password = document.getElementById('newUserPassword').value;

        if (!firstName || !lastName || !email || !phone || !role || !password) {
            showNotification('All fields are required.', 'error');
            return;
        }
        if (password.length < 8) {
            showNotification('Password must be at least 8 characters.', 'error');
            return;
        }

        var formData = new FormData();
        formData.append('first_name', firstName);
        formData.append('last_name', lastName);
        formData.append('email', email);
        formData.append('phone', phone);
        formData.append('user_type', role);
        formData.append('password', password);
        formData.append('action', 'add_user');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('admin-actions-secure.php', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) { showNotification('User created successfully!'); closeAddUserModal(); setTimeout(function() { location.reload(); }, 1000); }
            else { showNotification(data.message || 'Error creating user', 'error'); }
        })
        .catch(function(error) { console.error('Error:', error); showNotification('Error creating user', 'error'); });
    }

    function handleAddSchedule(event) {
        event.preventDefault();
        var doctorSelect = document.getElementById('scheduleDoctor');
        var doctorId = doctorSelect ? doctorSelect.value : '';
        if (!doctorId) {
            showNotification('Please select a doctor', 'error');
            return;
        }
        var formData = new FormData();
        formData.append('doctor_id', doctorId);
        formData.append('day_of_week', document.getElementById('scheduleDay').value);
        formData.append('start_time', document.getElementById('scheduleStartTime').value);
        formData.append('end_time', document.getElementById('scheduleEndTime').value);
        formData.append('slot_duration', document.getElementById('scheduleDuration').value);
        formData.append('max_patients', document.getElementById('scheduleMaxPatients').value);
        formData.append('action', 'add_schedule');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('admin-actions-secure.php', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) { showNotification('Schedule added successfully!'); closeAddScheduleModal(); setTimeout(function() { location.reload(); }, 1000); }
            else { showNotification(data.message || 'Error adding schedule', 'error'); }
        })
        .catch(function(error) { console.error('Error:', error); showNotification('Error adding schedule', 'error'); });
    }

    function handleAddService(event) {
        event.preventDefault();

        var nameEl = document.getElementById('serviceName');
        var descEl = document.getElementById('serviceDescription');
        var durEl  = document.getElementById('serviceDuration');
        var costEl = document.getElementById('serviceCost');

        var name = (nameEl.value || '').trim();
        var description = (descEl.value || '').trim();
        var durationStr = (durEl.value || '').trim();
        var costStr = (costEl.value || '').trim();

        // Client-side validation with clear messages
        if (!name) { showNotification('Service name is required.', 'error'); nameEl.focus(); return; }
        if (name.length < 2) { showNotification('Service name must be at least 2 characters.', 'error'); nameEl.focus(); return; }
        if (!description) { showNotification('Description is required.', 'error'); descEl.focus(); return; }
        if (description.length < 10) { showNotification('Description must be at least 10 characters.', 'error'); descEl.focus(); return; }
        if (durationStr === '' || isNaN(durationStr) || !/^\d+$/.test(durationStr)) {
            showNotification('Duration must be a whole number of minutes.', 'error'); durEl.focus(); return;
        }
        var duration = parseInt(durationStr, 10);
        if (duration < 1 || duration > 480) { showNotification('Duration must be between 1 and 480 minutes.', 'error'); durEl.focus(); return; }
        if (costStr === '' || isNaN(costStr)) { showNotification('Cost must be a valid number (in pesos).', 'error'); costEl.focus(); return; }
        var cost = parseFloat(costStr);
        if (cost < 0) { showNotification('Cost cannot be negative.', 'error'); costEl.focus(); return; }

        var formData = new FormData();
        formData.append('name', name);
        formData.append('description', description);
        formData.append('duration', String(duration));
        formData.append('cost', String(cost));
        formData.append('action', 'add_service');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('admin-actions-secure.php', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) { showNotification('Service added successfully!'); closeAddServiceModal(); setTimeout(function() { location.reload(); }, 1000); }
            else { showNotification(data.message || 'Error adding service', 'error'); }
        })
        .catch(function(error) { console.error('Error:', error); showNotification('Error adding service', 'error'); });
    }

    function updateClinicSettings(event) {
        event.preventDefault();
        var formData = new FormData();
        formData.append('clinic_name', document.getElementById('clinic_name').value);
        formData.append('clinic_phone', document.getElementById('clinic_phone').value);
        formData.append('clinic_email', document.getElementById('clinic_email').value);
        formData.append('clinic_address', document.getElementById('clinic_address').value);
        formData.append('appointment_reminder_hours', document.getElementById('appointment_reminder_hours').value);
        // "Get In Touch" footer fields
        formData.append('contact_address', document.getElementById('contact_address').value);
        formData.append('contact_phone',   document.getElementById('contact_phone').value);
        formData.append('contact_email',   document.getElementById('contact_email').value);
        formData.append('contact_hours',   document.getElementById('contact_hours').value);
        formData.append('action', 'update_clinic_settings');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('admin-actions-secure.php', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) { showNotification('Settings updated successfully!'); setTimeout(function() { location.reload(); }, 1000); }
            else { showNotification(data.message || 'Error updating settings', 'error'); }
        })
        .catch(function(error) { console.error('Error:', error); showNotification('Error updating settings', 'error'); });
    }

    // ============================================
    // Admin Calendar
    // ============================================
    var adminCalendarAppointments = <?php echo json_encode($calendar_appointments); ?>;
    var adminCalCurrentMonth = new Date().getMonth() + 1;
    var adminCalCurrentYear = new Date().getFullYear();

    function initAdminCalendar() {
        renderAdminCalendar(adminCalCurrentMonth, adminCalCurrentYear);
    }

    function renderAdminCalendar(month, year) {
        var container = document.getElementById('adminCalendar');
        if (!container) return;

        adminCalCurrentMonth = month;
        adminCalCurrentYear = year;

        var firstDay = new Date(year, month - 1, 1);
        var lastDay = new Date(year, month, 0);
        var prevLastDay = new Date(year, month - 1, 0);
        var firstDayIndex = firstDay.getDay();
        var nextDays = 7 - lastDay.getDay() - 1;
        if (nextDays < 0) nextDays = 6;

        var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

        // Count appointments per date
        var apptCounts = {};
        adminCalendarAppointments.forEach(function(a) {
            if (!apptCounts[a.appointment_date]) apptCounts[a.appointment_date] = 0;
            apptCounts[a.appointment_date]++;
        });

        var html = '<div class="calendar-container">' +
            '<div class="calendar-header">' +
            '<button class="calendar-nav-btn" onclick="navigateAdminCalendar(-1)"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>' +
            '<h3 class="calendar-month-year">' + months[month - 1] + ' ' + year + '</h3>' +
            '<button class="calendar-nav-btn" onclick="navigateAdminCalendar(1)"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>' +
            '</div>' +
            '<div class="calendar-weekdays"><div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div></div>' +
            '<div class="calendar-days">';

        var today = new Date();
        today.setHours(0,0,0,0);

        for (var x = firstDayIndex; x > 0; x--) {
            html += '<div class="calendar-day prev-month">' + (prevLastDay.getDate() - x + 1) + '</div>';
        }

        for (var day = 1; day <= lastDay.getDate(); day++) {
            var dateStr = year + '-' + String(month).padStart(2,'0') + '-' + String(day).padStart(2,'0');
            var dayClass = 'calendar-day clickable';
            var currentDay = new Date(year, month - 1, day);

            if (day === today.getDate() && (month - 1) === today.getMonth() && year === today.getFullYear()) {
                dayClass += ' today';
            }
            if (currentDay < today) {
                dayClass += ' past-date';
            }

            var count = apptCounts[dateStr] || 0;
            if (count > 0) dayClass += ' has-appointments';

            html += '<div class="' + dayClass + '" data-date="' + dateStr + '" onclick="showAdminDayAppointments(\'' + dateStr + '\')">' +
                '<span class="day-number">' + day + '</span>' +
                (count > 0 ? '<span class="appointment-indicator">' + count + '</span>' : '') +
                '</div>';
        }

        for (var j = 1; j <= nextDays; j++) {
            html += '<div class="calendar-day next-month">' + j + '</div>';
        }

        html += '</div></div>';
        container.innerHTML = html;
    }

    function navigateAdminCalendar(direction) {
        var newMonth = adminCalCurrentMonth + direction;
        var newYear = adminCalCurrentYear;
        if (newMonth < 1) { newMonth = 12; newYear--; }
        if (newMonth > 12) { newMonth = 1; newYear++; }
        renderAdminCalendar(newMonth, newYear);
    }

    function showAdminDayAppointments(dateStr) {
        var titleEl = document.getElementById('adminCalendarDateTitle');
        var container = document.getElementById('adminCalendarDayAppointments');
        if (!container) return;

        var dateObj = new Date(dateStr + 'T00:00:00');
        var options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
        if (titleEl) titleEl.textContent = dateObj.toLocaleDateString('en-US', options);

        var dayAppts = adminCalendarAppointments.filter(function(a) { return a.appointment_date === dateStr; });

        if (dayAppts.length === 0) {
            container.innerHTML = '<div class="text-center py-6"><svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><p class="text-gray-500 text-sm">No appointments</p></div>';
            return;
        }

        var html = '';
        dayAppts.forEach(function(appt) {
            var time = appt.appointment_time ? appt.appointment_time.substring(0,5) : '';
            var patient = (appt.patient_first_name || '') + ' ' + (appt.patient_last_name || '');
            var doctor = 'Dr. ' + (appt.doctor_first_name || '') + ' ' + (appt.doctor_last_name || '');
            var statusClass = 'bg-gray-100 text-gray-800';
            switch ((appt.status || '').toUpperCase()) {
                case 'CONFIRMED': statusClass = 'bg-green-100 text-green-800'; break;
                case 'SCHEDULED': statusClass = 'bg-orange-100 text-orange-800'; break;
                case 'COMPLETED': statusClass = 'bg-blue-100 text-blue-800'; break;
                case 'IN_PROGRESS': statusClass = 'bg-yellow-100 text-yellow-800'; break;
            }
            html += '<div class="p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">' +
                '<div class="flex justify-between items-start">' +
                '<div><div class="font-medium text-gray-800 text-sm">' + escapeHtml(patient) + '</div>' +
                '<div class="text-xs text-gray-500">' + escapeHtml(doctor) + '</div>' +
                '<div class="text-xs text-gray-500 mt-0.5"><svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' + escapeHtml(time) + '</div></div>' +
                '<span class="px-2 py-0.5 text-xs font-medium rounded-full ' + statusClass + '">' + escapeHtml(appt.status || '') + '</span>' +
                '</div></div>';
        });
        container.innerHTML = html;
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Init admin calendar when its section is shown
    var origAdminShowSection = window.showSection;
    var adminCalendarInitialized = false;
    window.showSection = function(sectionName) {
        origAdminShowSection(sectionName);
        if (sectionName === 'calendar' && !adminCalendarInitialized) {
            adminCalendarInitialized = true;
            initAdminCalendar();
        }
    };

    // ============================================
    // Announcements Management
    // ============================================
    function openAnnouncementFormModal() {
        document.getElementById('addAnnouncementModal').classList.remove('hidden');
    }

    function closeAnnouncementFormModal() {
        document.getElementById('addAnnouncementModal').classList.add('hidden');
        document.getElementById('addAnnouncementForm').reset();
    }

    function handleAddAnnouncement(event) {
        event.preventDefault();
        var formData = new FormData();
        formData.append('action', 'add_announcement');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        formData.append('title', document.getElementById('annTitle').value);
        formData.append('content', document.getElementById('annContent').value);
        formData.append('category', document.getElementById('annCategory').value);
        formData.append('priority', document.getElementById('annPriority').value);

        fetch('admin-actions-secure.php', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification('Announcement published!');
                closeAnnouncementFormModal();
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showNotification(data.message || 'Error adding announcement', 'error');
            }
        })
        .catch(function(error) { console.error('Error:', error); showNotification('Error adding announcement', 'error'); });
    }

    function toggleAnnouncementStatus(announcementId, newStatus) {
        var formData = new FormData();
        formData.append('action', 'toggle_announcement');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        formData.append('announcement_id', announcementId);
        formData.append('is_active', newStatus);

        fetch('admin-actions-secure.php', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) { showNotification('Announcement updated!'); setTimeout(function() { location.reload(); }, 1000); }
            else { showNotification(data.message || 'Error', 'error'); }
        })
        .catch(function(error) { showNotification('Error updating announcement', 'error'); });
    }

    function deleteAnnouncement(announcementId) {
        var run = function () {
            var formData = new FormData();
            formData.append('action', 'delete_announcement');
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            formData.append('announcement_id', announcementId);

            fetch('admin-actions-secure.php', { method: 'POST', body: formData })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) { showNotification('Announcement deleted!'); setTimeout(function() { location.reload(); }, 1000); }
                else { showNotification(data.message || 'Error', 'error'); }
            })
            .catch(function(error) { showNotification('Error deleting announcement', 'error'); });
        };
        if (typeof window.appConfirm === 'function') {
            window.appConfirm('Delete Announcement', 'Delete this announcement? This cannot be undone.', function(ok){ if (ok) run(); }, { confirmText: 'Delete' });
        } else if (confirm('Delete this announcement? This cannot be undone.')) {
            run();
        }
    }

    // ============================================
    // Edit User (SuperAdmin) — wires up the existing modal to handleUpdateUserProfile
    // ============================================
    function notify(msg, kind) {
        kind = kind || 'success';
        if (typeof showNotification === 'function') return showNotification(msg, kind === 'error' ? 'error' : '');
        if (typeof window.showToast === 'function') return window.showToast(msg, kind);
        alert(msg);
    }

    function openEditUserModal() {
        document.getElementById('editUserModal').classList.remove('hidden');
    }
    function closeEditUserModal() {
        document.getElementById('editUserModal').classList.add('hidden');
        var f = document.getElementById('editUserForm');
        if (f) f.reset();
    }

    function editUser(userId) {
        var row = document.querySelector('tr[data-user-id="' + userId + '"]');
        if (!row) { notify('User not found.', 'error'); return; }
        var raw = row.getAttribute('data-user');
        if (!raw) { notify('User data missing.', 'error'); return; }
        var u;
        try { u = JSON.parse(raw); } catch (e) { notify('Failed to read user.', 'error'); return; }

        document.getElementById('editUserId').value = u.id || '';
        document.getElementById('editUserFirstName').value = u.first_name || '';
        document.getElementById('editUserLastName').value = u.last_name || '';
        document.getElementById('editUserEmail').value = u.email || '';
        document.getElementById('editUserPhone').value = u.phone || '';
        document.getElementById('editUserDob').value = (u.date_of_birth && u.date_of_birth !== '0000-00-00') ? u.date_of_birth : '';
        document.getElementById('editUserGender').value = u.gender || '';
        document.getElementById('editUserAddress').value = u.address || '';
        document.getElementById('editUserEmergencyName').value = u.emergency_contact_name || '';
        document.getElementById('editUserEmergencyPhone').value = u.emergency_contact_phone || '';

        // Role select: doctor roles are not selectable here, so when editing a
        // doctor we replace the dropdown with a locked badge.
        var roleSel = document.getElementById('editUserRole');
        var roleHint = document.getElementById('editUserRoleHint');
        var isDoctor = (u.user_type === 'DOCTOR' || u.user_type === 'DOCTOR_OWNER');
        if (isDoctor) {
            roleSel.innerHTML = '<option value="' + u.user_type + '" selected>' + u.user_type + ' (locked)</option>';
            roleSel.disabled = true;
            if (roleHint) roleHint.classList.remove('hidden');
        } else {
            roleSel.innerHTML = '<option value="PARENT">Parent</option><option value="ADMIN">Admin</option><option value="SUPERADMIN">Super Admin</option>';
            roleSel.disabled = false;
            roleSel.value = u.user_type || 'PARENT';
            if (roleHint) roleHint.classList.add('hidden');
        }
        document.getElementById('editUserStatus').value = (u.status || 'active').toLowerCase();

        openEditUserModal();
    }

    function handleEditUser(e) {
        e.preventDefault();
        var fd = new FormData();
        fd.append('action', 'update_user_profile');
        fd.append('csrf_token', window.CSRF_TOKEN);
        fd.append('user_id', document.getElementById('editUserId').value);
        fd.append('first_name', document.getElementById('editUserFirstName').value.trim());
        fd.append('last_name', document.getElementById('editUserLastName').value.trim());
        fd.append('email', document.getElementById('editUserEmail').value.trim());
        fd.append('phone', document.getElementById('editUserPhone').value.trim());
        fd.append('date_of_birth', document.getElementById('editUserDob').value);
        fd.append('gender', document.getElementById('editUserGender').value);
        fd.append('address', document.getElementById('editUserAddress').value.trim());
        fd.append('emergency_contact_name', document.getElementById('editUserEmergencyName').value.trim());
        fd.append('emergency_contact_phone', document.getElementById('editUserEmergencyPhone').value.trim());
        var roleEl = document.getElementById('editUserRole');
        if (!roleEl.disabled) fd.append('user_type', roleEl.value);
        else fd.append('user_type', roleEl.value);
        fd.append('status', document.getElementById('editUserStatus').value);

        fetch('admin-actions-secure.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) {
                    notify(d.message || 'User updated.');
                    closeEditUserModal();
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    notify(d.message || 'Failed to update user.', 'error');
                }
            })
            .catch(function(){ notify('Network error. Please try again.', 'error'); });
    }

    <?php if ($is_superadmin): ?>
    // ============================================
    // SuperAdmin: Add Child / Patient Profile
    // ============================================
    function openAddPatientModal() {
        document.getElementById('addPatientModal').classList.remove('hidden');
    }
    function closeAddPatientModal() {
        document.getElementById('addPatientModal').classList.add('hidden');
        var f = document.getElementById('addPatientForm'); if (f) f.reset();
    }
    function handleAddPatient(e) {
        e.preventDefault();
        var fd = new FormData();
        fd.append('action', 'add_patient');
        fd.append('csrf_token', window.CSRF_TOKEN);
        fd.append('parent_id', document.getElementById('patientParentId').value);
        fd.append('first_name', document.getElementById('patientFirstName').value.trim());
        fd.append('last_name', document.getElementById('patientLastName').value.trim());
        fd.append('date_of_birth', document.getElementById('patientDob').value);
        fd.append('gender', document.getElementById('patientGender').value);
        fd.append('blood_type', document.getElementById('patientBloodType').value);
        fd.append('height', document.getElementById('patientHeight').value);
        fd.append('weight', document.getElementById('patientWeight').value);
        fd.append('allergies', document.getElementById('patientAllergies').value.trim());
        fd.append('medical_conditions', document.getElementById('patientConditions').value.trim());

        fetch('admin-actions-secure.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) {
                    notify(d.message || 'Child profile added.');
                    closeAddPatientModal();
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    notify(d.message || 'Failed to add profile.', 'error');
                }
            })
            .catch(function(){ notify('Network error. Please try again.', 'error'); });
    }

    // ============================================
    // SuperAdmin: Create Appointment
    // ============================================
    function openCreateAppointmentModal() {
        document.getElementById('createAppointmentModal').classList.remove('hidden');
    }
    function closeCreateAppointmentModal() {
        document.getElementById('createAppointmentModal').classList.add('hidden');
        var f = document.getElementById('createAppointmentForm'); if (f) f.reset();
    }
    function handleCreateAppointment(e) {
        e.preventDefault();
        var fd = new FormData();
        fd.append('action', 'create_appointment');
        fd.append('csrf_token', window.CSRF_TOKEN);
        fd.append('patient_id', document.getElementById('apptPatientId').value);
        fd.append('doctor_id', document.getElementById('apptDoctorId').value);
        fd.append('appointment_date', document.getElementById('apptDate').value);
        fd.append('appointment_time', document.getElementById('apptTime').value);
        fd.append('type', document.getElementById('apptType').value);
        fd.append('reason', document.getElementById('apptReason').value.trim());

        fetch('admin-actions-secure.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) {
                    notify(d.message || 'Appointment created.');
                    closeCreateAppointmentModal();
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    notify(d.message || 'Failed to create appointment.', 'error');
                }
            })
            .catch(function(){ notify('Network error. Please try again.', 'error'); });
    }

    // ============================================
    // SuperAdmin: Doctor Schedule actions
    // ============================================
    function openAddScheduleModal() {
        document.getElementById('addScheduleModal').classList.remove('hidden');
    }
    function closeAddScheduleModal() {
        document.getElementById('addScheduleModal').classList.add('hidden');
        var f = document.getElementById('addScheduleForm'); if (f) f.reset();
    }
    function deleteDoctorSchedule(scheduleId) {
        var run = function () {
            var fd = new FormData();
            fd.append('action', 'delete_schedule');
            fd.append('csrf_token', window.CSRF_TOKEN);
            fd.append('schedule_id', scheduleId);
            fetch('admin-actions-secure.php', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.success) { notify(d.message || 'Schedule removed.'); setTimeout(function(){ location.reload(); }, 800); }
                    else { notify(d.message || 'Failed to delete schedule.', 'error'); }
                })
                .catch(function(){ notify('Network error. Please try again.', 'error'); });
        };
        if (typeof window.appConfirm === 'function') {
            window.appConfirm('Delete Schedule', 'Delete this doctor schedule entry?', function(ok){ if (ok) run(); }, { confirmText: 'Delete' });
        } else if (confirm('Delete this doctor schedule entry?')) {
            run();
        }
    }
    <?php endif; ?>
    </script>
    <script src="js/admin-dashboard.js"></script>
</body>
</html>