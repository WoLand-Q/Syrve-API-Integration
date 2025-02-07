<?php
/**
 *  веб-приложения на PHP для управления водителем доставки (Syrve / iikoCloud):
 *  1) Авторизация по apiLogin (GET /access_token).
 *  2) Получение списка организаций (POST /organizations).
 *  3) Получение списка курьеров (POST /employees/couriers).
 *  4) Ввод orderId, выбор курьера => вызов change_driver_info (POST /deliveries/change_driver_info).
 *  5) Отслеживание статуса команды через POST /commands/status.
 */

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

session_start();

// ================== НАСТРОЙКИ ==================
define('SYRVE_API_HOST', 'https://api-eu.syrve.live/api/1');
define('API_LOGIN', ''); // <-- Вставьте ваш apiLogin строку здесь

// ================== ФУНКЦИИ ==================

/** Выполняет POST-запрос c JSON в Syrve API. Возвращает ассоциативный массив (ответ JSON или ошибка). */
function postJson($url, array $payload, $bearerToken = null) {
    $ch = curl_init($url);
    $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $headers = ['Content-Type: application/json'];
    if ($bearerToken) {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['error' => $error, 'httpCode' => $httpCode];
    }
    if ($httpCode >= 400) {
        return ['error' => "HTTP $httpCode", 'response' => $response];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'JSON parse error', 'raw' => $response];
    }
    return $decoded;
}

/** Получает Bearer-токен по apiLogin (POST /access_token). */
function getAccessToken($apiLogin) {
    $url = SYRVE_API_HOST . '/access_token';
    $payload = ["apiLogin" => $apiLogin];
    $result = postJson($url, $payload);
    if (!empty($result['error'])) {
        return null;
    }
    return $result['token'] ?? null;
}

/** Получает список организаций (POST /organizations). */
function getOrganizations($bearer) {
    $url = SYRVE_API_HOST . '/organizations';
    $payload = [
        "organizationIds" => [],
        "returnAdditionalInfo" => true,
        "includeDisabled" => true
    ];
    return postJson($url, $payload, $bearer);
}

/** Получает список водителей (POST /employees/couriers). */
function getCouriers($bearer, array $organizationIds) {
    $url = SYRVE_API_HOST . '/employees/couriers';
    $payload = ["organizationIds" => $organizationIds];
    return postJson($url, $payload, $bearer);
}

/** Меняет водителя для заказа (POST /deliveries/change_driver_info). */
function changeDriverInfo($bearer, $orgId, $orderId, $driverId, $estimatedTime = null) {
    $url = SYRVE_API_HOST . '/deliveries/change_driver_info';
    $payload = [
        "organizationId" => $orgId,
        "orderId"        => $orderId,
    ];
    if ($driverId !== '') {
        $payload["driverId"] = $driverId;
    }
    if ($estimatedTime) {
        $payload["estimatedTime"] = $estimatedTime; // формат "YYYY-MM-DD HH:mm:ss.fff"
    }
    return postJson($url, $payload, $bearer);
}

/** Получает статус команды через (POST /commands/status) */
function getCommandStatus($bearer, $organizationId, $correlationId) {
    $url = SYRVE_API_HOST . '/commands/status';
    $payload = [
        "organizationId" => $organizationId,
        "correlationId"  => $correlationId
    ];
    return postJson($url, $payload, $bearer);
}

// ================== ЛОГИКА ==================

// 1) Проверка, есть ли токен в сессии
if (empty($_SESSION['syrveBearer'])) {
    $token = getAccessToken(API_LOGIN);
    if (!$token) {
        die("Не удалось получить токен по apiLogin. Проверьте API_LOGIN.");
    }
    $_SESSION['syrveBearer'] = $token;
}
$bearer = $_SESSION['syrveBearer'];

// 2) Загружаем список организаций
$orgResponse = getOrganizations($bearer);
if (!empty($orgResponse['error'])) {
    die("Ошибка при запросе организаций: " . print_r($orgResponse, true));
}
$orgs = $orgResponse['organizations'] ?? [];
if (!$orgs) {
    die("Нет доступных организаций.");
}

// Текущая выбранная организация: из POST, иначе берем первую
$selectedOrgId = $_POST['orgId'] ?? $orgs[0]['id'];

// 3) Для выбранной организации получим список водителей (курьеров)
$drivers = [];
if ($selectedOrgId) {
    $resp = getCouriers($bearer, [$selectedOrgId]);
    if (empty($resp['error']) && !empty($resp['employees'])) {
        foreach ($resp['employees'] as $block) {
            if (!empty($block['items'])) {
                foreach ($block['items'] as $drv) {
                    $drivers[] = $drv;
                }
            }
        }
    }
}

// 4) Обработка формы «Сменить курьера»
$message = null;
if (isset($_POST['action']) && $_POST['action'] === 'changeDriver') {
    $orderId = trim($_POST['orderId'] ?? '');
    $driverId = trim($_POST['driverId'] ?? '');
    $estTime  = trim($_POST['estimatedTime'] ?? '');

    if ($orderId === '') {
        $message = "Пожалуйста, введите OrderId (UUID заказа).";
    } else {
        // Вызываем change_driver_info
        $respChange = changeDriverInfo($bearer, $selectedOrgId, $orderId, $driverId, $estTime);
        if (!empty($respChange['error'])) {
            $message = "Ошибка change_driver_info: " . print_r($respChange, true);
        } else {
            // ОК, смотрим correlationId
            $corrId = $respChange['correlationId'] ?? '';
            if (!$corrId) {
                $message = "Не вернулся correlationId. Ответ: " . print_r($respChange, true);
            } else {
                // Спим 2 сек, проверяем статус
                sleep(2);
                $statusResp = getCommandStatus($bearer, $selectedOrgId, $corrId);
                if (!empty($statusResp['error'])) {
                    $message = "Ошибка getCommandStatus: " . print_r($statusResp, true);
                } else {
                    $state = $statusResp['state'] ?? 'Unknown';
                    if ($state === 'InProgress') {
                        $message = "Команда в процессе (InProgress). CorrId=$corrId";
                    } elseif ($state === 'Success') {
                        $message = "Успешно сменили водителя. Статус = Success.";
                    } elseif ($state === 'Error') {
                        // иногда "errorDescription" и "error" могут приходить
                        // в других полях - в зависимости от версии
                        $message = "Команда вернула статус Error: " . print_r($statusResp, true);
                    } else {
                        $message = "Статус команды: $state. CorrId=$corrId";
                    }
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"/>
    <title>Смена водителя (Syrve/iikoCloud)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container my-4">
    <h1>Изменение водителя через Syrve/iikoCloud API</h1>

    <?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" class="row gx-3 gy-2 align-items-center">
        <input type="hidden" name="action" value="changeDriver">
        <div class="col-auto">
            <label for="orgId" class="form-label">Организация:</label>
            <select name="orgId" id="orgId" class="form-select" onchange="this.form.submit()">
                <?php foreach ($orgs as $org): ?>
                    <?php $oid = $org['id']; ?>
                    <option value="<?= htmlspecialchars($oid) ?>"
                        <?= ($oid === $selectedOrgId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($org['name'] ?? $oid) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <label for="orderId" class="form-label">OrderId:</label>
            <input type="text" name="orderId" id="orderId" class="form-control"
                   placeholder="UUID заказа (обязательно)">
        </div>

        <div class="col-auto">
            <label for="driverId" class="form-label">Курьер (driverId):</label>
            <select name="driverId" id="driverId" class="form-select">
                <option value="">(убрать водителя)</option>
                <?php foreach ($drivers as $drv): ?>
                    <?php
                      $dId = $drv['id'];
                      $dName = $drv['displayName'] ?? ($drv['firstName'] ?? $dId);
                    ?>
                    <option value="<?= htmlspecialchars($dId) ?>">
                        <?= htmlspecialchars($dName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <label for="estimatedTime" class="form-label">Время доставки:</label>
            <input type="text" name="estimatedTime" id="estimatedTime" class="form-control"
                   placeholder="2025-02-01 14:15:22.123 (необязательно)">
        </div>

        <div class="col-auto">
            <button type="submit" class="btn btn-primary">Сменить курьера</button>
        </div>
    </form>

    <hr>
    <h3>Список курьеров для выбранной организации</h3>
    <table class="table table-bordered table-striped">
        <thead>
        <tr>
            <th>Driver ID</th>
            <th>Code</th>
            <th>DisplayName</th>
            <th>Удален?</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($drivers): ?>
            <?php foreach ($drivers as $drv): ?>
                <tr>
                    <td><?= htmlspecialchars($drv['id']) ?></td>
                    <td><?= htmlspecialchars($drv['code'] ?? '') ?></td>
                    <td><?= htmlspecialchars($drv['displayName'] ?? $drv['id']) ?></td>
                    <td><?= !empty($drv['isDeleted']) ? 'Да' : 'Нет' ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="4">Нет водителей для данной организации.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
