<?php
/**
 * Admin-only presentation helpers for explicit, context-safe parent navigation.
 * Fixed route maps and scalar allowlists prevent return links from becoming open redirects.
 */

/**
 * Build a local parent URL while retaining only approved search and pagination context.
 *
 * @param string $parent Known logical parent key.
 * @param array $params Candidate query context, filtered by the helper.
 * @return string A local application URL.
 */
function admin_parent_url(string $parent, array $params = []): string
{
    $parents = [
        'dashboard' => ['path' => 'admin/index.php', 'label' => 'لوحة الإدارة'],
        'users' => ['path' => 'admin/users.php', 'label' => 'المستخدمون'],
        'requests' => ['path' => 'admin/requests.php', 'label' => 'الطلبات'],
        'reports' => ['path' => 'admin/reports.php', 'label' => 'البلاغات'],
        'report-view' => ['path' => 'admin/report_view.php', 'label' => 'تفاصيل البلاغ'],
        'assistance-types' => ['path' => 'admin/assistance_types.php', 'label' => 'أنواع المساعدة'],
        'majors' => ['path' => 'admin/majors.php', 'label' => 'التخصصات'],
        'logs' => ['path' => 'admin/logs.php', 'label' => 'سجل الإدارة'],
        'notifications' => ['path' => 'admin/notifications.php', 'label' => 'الإشعارات'],
    ];
    if (!isset($parents[$parent])) {
        $parent = 'dashboard';
    }

    $safe = [];
    foreach (['id', 'page', 'per_page', 'back_page', 'back_per_page'] as $key) {
        $value = $params[$key] ?? null;
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0) {
            $safe[$key] = (int)$value;
        }
    }
    $perPage = (int)($safe['per_page'] ?? $safe['back_per_page'] ?? 20);
    if (!in_array($perPage, [10, 20, 50], true)) {
        unset($safe['per_page'], $safe['back_per_page']);
    }
    $q = $params['q'] ?? null;
    if (is_string($q) && $q !== '') {
        $safe['q'] = $q;
    }
    $backQ = $params['back_q'] ?? null;
    if (is_string($backQ) && $backQ !== '') {
        $safe['back_q'] = mb_substr($backQ, 0, 120);
    }

    $query = http_build_query($safe, '', '&', PHP_QUERY_RFC3986);
    return app_url($parents[$parent]['path'] . ($query !== '' ? '?' . $query : ''));
}

/** Build a URL for one of the allowlisted admin detail routes. */
function admin_detail_url(string $page, array $params = []): string
{
    $routes = [
        'user_view.php' => 'admin/user_view.php',
        'request_view.php' => 'admin/request_view.php',
        'report_view.php' => 'admin/report_view.php',
        'reported_conversation.php' => 'admin/reported_conversation.php',
    ];
    if (!isset($routes[$page])) {
        return app_url('admin/index.php');
    }
    $safe = [];
    foreach (['id', 'report_id', 'back_page', 'back_per_page'] as $key) {
        $value = $params[$key] ?? null;
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0) {
            $safe[$key] = (int)$value;
        }
    }
    $perPage = (int)($safe['back_per_page'] ?? 20);
    if (!in_array($perPage, [10, 20, 50], true)) {
        unset($safe['back_per_page']);
    }
    foreach (['back_q'] as $key) {
        if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
            $safe[$key] = $params[$key];
        }
    }
    $query = http_build_query($safe, '', '&', PHP_QUERY_RFC3986);
    return app_url($routes[$page] . ($query !== '' ? '?' . $query : ''));
}

/** Render an explicit parent link; it does not rely on browser history. */
function admin_page_back_link(string $parent, array $params = []): string
{
    $url = admin_parent_url($parent, $params);
    return '<div class="admin-back-row"><a class="admin-back-link" href="' . e($url) . '"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>رجوع</span></a></div>';
}

/** Optional compact breadcrumb for detail/review pages; the final crumb is plain text. */
/** Render escaped breadcrumb labels, linking ancestors and leaving the current page as text. */
function admin_breadcrumbs(array $items): string
{
    if (count($items) < 2) {
        return '';
    }
    $html = '<nav class="admin-breadcrumbs" aria-label="مسار لوحة الإدارة">';
    foreach (array_values($items) as $index => $item) {
        if (!is_array($item) || !isset($item['label']) || !is_string($item['label'])) {
            continue;
        }
        if ($index > 0) {
            $html .= '<i class="bi bi-chevron-left" aria-hidden="true"></i>';
        }
        if ($index < count($items) - 1 && isset($item['url']) && is_string($item['url'])) {
            $html .= '<a href="' . e($item['url']) . '">' . e($item['label']) . '</a>';
        } else {
            $html .= '<span aria-current="page">' . e($item['label']) . '</span>';
        }
    }
    return $html . '</nav>';
}

/** Render a shared page heading from escaped text and a Bootstrap Icon name. */
function admin_page_intro(string $title, string $description = '', string $icon = 'grid-1x2'): string
{
    $html = '<header class="ux-page-header admin-page-header"><div><span class="eyebrow"><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i> إدارة المنصة</span><h1>' . e($title) . '</h1>';
    if ($description !== '') {
        $html .= '<p>' . e($description) . '</p>';
    }
    return $html . '</div></header>';
}
