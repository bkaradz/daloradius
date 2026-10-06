<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 */

    include("library/checklogin.php");
    $operator = $_SESSION['operator_user'];

    include("library/check_operator_perm.php");
    include_once implode(DIRECTORY_SEPARATOR, [ __DIR__, '..', 'common', 'includes', 'config_read.php' ]);
    include_once implode(DIRECTORY_SEPARATOR, [ $configValues['OPERATORS_LANG'], 'main.php' ]);
    include implode(DIRECTORY_SEPARATOR, [ $configValues['COMMON_INCLUDES'], 'layout.php' ]);

    include implode(DIRECTORY_SEPARATOR, [ $configValues['COMMON_INCLUDES'], 'validation.php' ]);

    $log = "visited page: ";
    $logQuery = "performed query on page: ";
    $logDebugSQL = "";

    $logAction = "";
    $successMsg = "";
    $failureMsg = "";
    $selectedBatchId = "";
    $printRows = array();
    $batchRows = array();
    $batchOptions = array();
    $batchNamesById = array();

    function mng_print_batch_user_count($dbSocket, $configValues, $batchId) {
        if ($batchId < 1) {
            return 0;
        }

        $sql = sprintf("SELECT COUNT(*) FROM %s WHERE batch_id = %d AND COALESCE(printed, 0) = 0",
                       $configValues['CONFIG_DB_TBL_DALOUSERBILLINFO'],
                       intval($batchId));
        return intval($dbSocket->getOne($sql));
    }

    function mng_print_batch_users($dbSocket, $configValues, $batchId, $limit, $offset) {
        if ($batchId < 1) {
            return array();
        }

        $sql = "SELECT ubi.id, ubi.username, MAX(CAST(rgc.value AS UNSIGNED)) AS max_session_seconds,
                       EXISTS (
                           SELECT 1 FROM %s AS ra
                            WHERE ra.username = ubi.username AND ra.acctstarttime IS NOT NULL
                       ) AS accounted, COALESCE(ubi.printed, 0) AS printed
                  FROM %s AS ubi
             LEFT JOIN %s AS rug ON rug.username = ubi.username
             LEFT JOIN %s AS rgc ON rgc.groupname = rug.groupname AND rgc.attribute = 'Max-All-Session'
                 WHERE ubi.batch_id = %d
                   AND COALESCE(ubi.printed, 0) = 0
              GROUP BY ubi.id, ubi.username, ubi.printed
              ORDER BY ubi.id ASC
                 LIMIT %d, %d";
        $sql = sprintf($sql, $configValues['CONFIG_DB_TBL_RADACCT'],
                             $configValues['CONFIG_DB_TBL_DALOUSERBILLINFO'],
                             $configValues['CONFIG_DB_TBL_RADUSERGROUP'],
                             $configValues['CONFIG_DB_TBL_RADGROUPCHECK'],
                             intval($batchId),
                             max(0, intval($offset)),
                             max(1, intval($limit)));

        $res = $dbSocket->query($sql);
        $rows = array();
        while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
            $row['id'] = intval($row['id']);
            $row['username'] = (string)$row['username'];
            $row['max_session_seconds'] = ($row['max_session_seconds'] === null)
                                        ? null : intval($row['max_session_seconds']);
            $row['accounted'] = intval($row['accounted']);
            $row['printed'] = intval($row['printed']);
            $rows[] = $row;
        }

        return $rows;
    }

    function mng_print_candidates($dbSocket, $configValues, $batchId, $userIds = array()) {
        if ($batchId < 1) {
            return array();
        }

        $idFilter = "";
        if (count($userIds) > 0) {
            $idFilter = sprintf(" AND ubi.id IN (%s)", implode(",", array_map('intval', $userIds)));
        }

        $sql = "SELECT ubi.id, ubi.username, MAX(CAST(rgc.value AS UNSIGNED)) AS max_session_seconds
                  FROM %s AS ubi
             LEFT JOIN %s AS rug ON rug.username = ubi.username
             LEFT JOIN %s AS rgc ON rgc.groupname = rug.groupname AND rgc.attribute = 'Max-All-Session'
                 WHERE ubi.batch_id = %d
                   AND COALESCE(ubi.printed, 0) = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM %s AS ra
                        WHERE ra.username = ubi.username AND ra.acctstarttime IS NOT NULL
                   )%s
              GROUP BY ubi.id, ubi.username
              ORDER BY ubi.id ASC";
        $sql = sprintf($sql, $configValues['CONFIG_DB_TBL_DALOUSERBILLINFO'],
                             $configValues['CONFIG_DB_TBL_RADUSERGROUP'],
                             $configValues['CONFIG_DB_TBL_RADGROUPCHECK'],
                             intval($batchId),
                             $configValues['CONFIG_DB_TBL_RADACCT'],
                             $idFilter);

        $res = $dbSocket->query($sql);
        $rows = array();
        while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
            $row['id'] = intval($row['id']);
            $row['username'] = (string)$row['username'];
            $row['max_session_seconds'] = ($row['max_session_seconds'] === null)
                                        ? null : intval($row['max_session_seconds']);
            $rows[] = $row;
        }

        return $rows;
    }

    function mng_print_duration($seconds) {
        if ($seconds === null || $seconds < 1) {
            return strip_tags(t('all', 'NotAvailable'));
        }

        if ($seconds < 3600) {
            return sprintf("%d min", max(1, (int)ceil($seconds / 60)));
        }

        $hours = rtrim(rtrim(number_format($seconds / 3600, 2, '.', ''), '0'), '.');
        return sprintf("%s %s", $hours, ($hours === "1") ? "hour" : "hours");
    }

    function mng_print_safe_text($value) {
        return preg_replace('/[\x00-\x1F\x7F]/', '', (string)$value);
    }

    function mng_print_page_links($batchId, $page, $maxPage, $pageSize) {
        if ($maxPage <= 1) {
            return;
        }

        $startPage = max(1, $page - 2);
        $endPage = min($maxPage, $page + 2);
        if ($page <= 3) {
            $endPage = min($maxPage, 5);
        } elseif ($page >= $maxPage - 2) {
            $startPage = max(1, $maxPage - 4);
        }

        echo '<nav aria-label="Batch user pages"><ul class="pagination pagination-sm mb-0">';
        foreach (range($startPage, $endPage) as $pageLink) {
            $query = http_build_query(array(
                'batch_id' => 'batch_' . intval($batchId),
                'page' => $pageLink,
                'per_page' => $pageSize,
            ));
            if ($pageLink === $page) {
                printf('<li class="page-item active" aria-current="page"><span class="page-link">%d</span></li>', $pageLink);
            } else {
                printf('<li class="page-item"><a class="page-link" href="mng-print.php?%s">%d</a></li>',
                       htmlspecialchars($query, ENT_QUOTES, 'UTF-8'),
                       $pageLink);
            }
        }
        echo '</ul></nav>';
    }

    include implode(DIRECTORY_SEPARATOR, [ $configValues['COMMON_INCLUDES'], 'db_open.php' ]);

    $sql = sprintf("SELECT id, batch_name FROM %s WHERE batch_name IS NOT NULL AND batch_name <> '' ORDER BY batch_name ASC, id ASC",
                   $configValues['CONFIG_DB_TBL_DALOBATCHHISTORY']);
    $res = $dbSocket->query($sql);
    $logDebugSQL .= "$sql;\n";
    while ($row = $res->fetchRow()) {
        $batchId = intval($row[0]);
        $batchName = (string)$row[1];
        $batchOptions[(string)$batchId] = $batchName;
        $batchNamesById[$batchId] = $batchName;
    }

    $requestBatchId = ($_SERVER['REQUEST_METHOD'] === 'POST')
                    ? ($_POST['batch_id'] ?? '')
                    : ($_GET['batch_id'] ?? '');
    if (is_scalar($requestBatchId) && preg_match('/^(?:batch_)?([0-9]+)$/', (string)$requestBatchId, $batchMatch)) {
        $requestedId = intval($batchMatch[1]);
        if (array_key_exists($requestedId, $batchNamesById)) {
            $selectedBatchId = (string)$requestedId;
        }
    }

    $pageSizeOptions = array(5, 10, 15, 20, 25);
    $requestedPageSize = $_REQUEST['per_page'] ?? 25;
    $pageSize = (is_scalar($requestedPageSize) && ctype_digit((string)$requestedPageSize) &&
                 in_array(intval($requestedPageSize), $pageSizeOptions, true))
              ? intval($requestedPageSize) : 25;
    $requestedPage = $_REQUEST['page'] ?? 1;
    $page = (is_scalar($requestedPage) && ctype_digit((string)$requestedPage) && intval($requestedPage) > 0)
          ? intval($requestedPage) : 1;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) ||
            !dalo_check_csrf_token($_POST['csrf_token'])) {
            $failureMsg = "The request could not be verified. Please reload the page and try again.";
        } elseif ($selectedBatchId === "") {
            $failureMsg = "Select a valid batch before printing.";
        } elseif (!isset($_POST['user_ids']) || !is_array($_POST['user_ids'])) {
            $failureMsg = "Select at least one user to print.";
        } else {
            $userIds = array();
            foreach ($_POST['user_ids'] as $userId) {
                if (!is_scalar($userId) || !ctype_digit((string)$userId) || intval($userId) < 1) {
                    $userIds = array();
                    break;
                }
                $userIds[] = intval($userId);
            }
            $userIds = array_values(array_unique($userIds));

            if (count($userIds) === 0) {
                $failureMsg = "Select at least one valid user to print.";
            } else {
                $printRows = mng_print_candidates($dbSocket, $configValues, intval($selectedBatchId), $userIds);
                if (count($printRows) === 0) {
                    $failureMsg = "None of the selected users are available to print.";
                } else {
                    $vendorAutoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
                    if (!is_readable($vendorAutoload)) {
                        $failureMsg = "ESC/POS printer support is not installed. Run composer install and try again.";
                    } elseif (!class_exists('IntlBreakIterator')) {
                        $failureMsg = "PHP's intl extension is required for ESC/POS printing. Install the PHP intl package and restart Apache.";
                    } else {
                        try {
                            require_once $vendorAutoload;
                            $connector = new \Mike42\Escpos\PrintConnectors\NetworkPrintConnector("192.168.100.250", 9100);
                            $printer = new \Mike42\Escpos\Printer($connector);
                            foreach ($printRows as $printRow) {
                                $printer->setJustification(\Mike42\Escpos\Printer::JUSTIFY_CENTER);
                                $printer->selectPrintMode(\Mike42\Escpos\Printer::MODE_EMPHASIZED | \Mike42\Escpos\Printer::MODE_DOUBLE_WIDTH);
                                $printer->text("HOTSPOT VOUCHER\n");
                                $printer->selectPrintMode();
                                $printer->setJustification(\Mike42\Escpos\Printer::JUSTIFY_LEFT);
                                $printer->text("Wi-Fi:      Fast Data \n");
                                $printer->text("Username:   " . mng_print_safe_text($printRow['username']) . "\n");
                                $printer->text("Duration:   " . mng_print_duration($printRow['max_session_seconds']) . "\n");
                                $printer->text("Batch:      " . mng_print_safe_text($batchNamesById[intval($selectedBatchId)]) . "\n");
                                $printer->text("Date:       " . date("d/m/Y H:i") . "\n");
                                $printer->feed(1);
                                $printer->cut();
                            }
                            $printer->close();

                            $printedIds = array_map(function ($row) {
                                return intval($row['id']);
                            }, $printRows);
                            $sql = sprintf("UPDATE %s SET printed = 1 WHERE batch_id = %d AND id IN (%s) AND COALESCE(printed, 0) = 0",
                                           $configValues['CONFIG_DB_TBL_DALOUSERBILLINFO'],
                                           intval($selectedBatchId),
                                           implode(",", $printedIds));
                            $updateRes = $dbSocket->query($sql);
                            $logDebugSQL .= "$sql;\n";
                            if (DB::isError($updateRes)) {
                                $failureMsg = "The tickets were sent to the printer, but their printed status could not be saved.";
                            } else {
                                $successMsg = sprintf("%d user ticket(s) printed.", count($printRows));
                                $logAction .= sprintf("Printed %d batch user ticket(s) for batch [%s] on page: ",
                                                      count($printRows), $batchNamesById[intval($selectedBatchId)]);
                            }
                            $printRows = array();
                        } catch (Throwable $exception) {
                            $failureMsg = "Printing failed: " . $exception->getMessage();
                            $logAction .= "Printing failed for batch [" .
                                          $batchNamesById[intval($selectedBatchId)] . "] on page: ";
                        }
                    }
                }
            }
        }
    }

    $batchUserCount = 0;
    $maxPage = 1;
    if ($selectedBatchId !== "") {
        $batchUserCount = mng_print_batch_user_count($dbSocket, $configValues, intval($selectedBatchId));
        $maxPage = max(1, (int)ceil($batchUserCount / $pageSize));
        $page = min($page, $maxPage);
        $offset = ($page - 1) * $pageSize;
        $batchRows = mng_print_batch_users($dbSocket, $configValues, intval($selectedBatchId), $pageSize, $offset);
        $printRows = mng_print_candidates($dbSocket, $configValues, intval($selectedBatchId));
    }

    $printBatchOptions = array("0" => "Select Batch");
    foreach ($batchOptions as $id => $name) {
        $printBatchOptions["batch_" . $id] = $name;
    }
    $selectedPrintBatch = ($selectedBatchId === "") ? "" : "batch_" . $selectedBatchId;

    include implode(DIRECTORY_SEPARATOR, [ $configValues['COMMON_INCLUDES'], 'db_close.php' ]);

    $extraJs = array("static/js/mng-print.js");
    $title = t('Intro', 'mngprint.php');
    print_html_prologue($title, $langCode, array(), $extraJs);
    print_title_and_help($title, "");

    if ($successMsg !== "") {
        printf('<div class="alert alert-success" role="alert">%s</div>', htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'));
    }
    if ($failureMsg !== "") {
        printf('<div class="alert alert-danger" role="alert">%s</div>', htmlspecialchars($failureMsg, ENT_QUOTES, 'UTF-8'));
    }

    if ($selectedBatchId === "") {
        echo '<div class="alert alert-info" role="status">Select a batch from the sidebar to view users available for printing.</div>';
    } else {
        printf('<h2 class="fs-5">%s</h2>', htmlspecialchars($batchNamesById[intval($selectedBatchId)], ENT_QUOTES, 'UTF-8'));
        if ($batchUserCount === 0) {
            echo '<div class="alert alert-info" role="status">There are no users in this batch.</div>';
        } else {
            echo '<form method="POST" action="mng-print.php" id="print-users-form">';
            printf('<input type="hidden" name="batch_id" value="%s">', htmlspecialchars($selectedBatchId, ENT_QUOTES, 'UTF-8'));
            printf('<input type="hidden" name="csrf_token" value="%s">', htmlspecialchars(dalo_csrf_token(), ENT_QUOTES, 'UTF-8'));
            printf('<input type="hidden" name="page" value="%d">', $page);
            printf('<input type="hidden" name="per_page" value="%d">', $pageSize);
            echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">';
            echo '<div class="d-flex align-items-center flex-wrap gap-3">';
            mng_print_page_links(intval($selectedBatchId), $page, $maxPage, $pageSize);
            echo '<div class="d-flex align-items-center gap-2"><label class="form-label mb-0" for="print-page-size">Records per page</label>';
            echo '<select class="form-select form-select-sm w-auto" id="print-page-size">';
            foreach ($pageSizeOptions as $pageSizeOption) {
                printf('<option value="%d"%s>%d</option>',
                       $pageSizeOption,
                       $pageSizeOption === $pageSize ? ' selected' : '',
                       $pageSizeOption);
            }
            echo '</select></div></div>';
            printf('<button type="submit" class="btn btn-primary" id="print-selected-button" disabled>%s</button>',
                   htmlspecialchars(t('button', 'PrintSelected'), ENT_QUOTES, 'UTF-8'));
            echo '</div>';
            echo '<div class="table-responsive"><table class="table table-striped table-hover align-middle">';
            echo '<thead><tr>';
            echo '<th scope="col"><input class="form-check-input" type="checkbox" id="select-all-users" aria-label="Select all users"></th>';
            printf('<th scope="col">%s</th>', htmlspecialchars(t('all', 'Username'), ENT_QUOTES, 'UTF-8'));
            echo '<th scope="col">Accounted</th>';
            printf('<th scope="col">%s</th>', htmlspecialchars(t('all', 'MaxAllSession'), ENT_QUOTES, 'UTF-8'));
            echo '</tr></thead><tbody>';
            $selectableCount = 0;
            foreach ($batchRows as $batchRow) {
                $accounted = $batchRow['accounted'] === 1;
                $selectable = !$accounted && $batchRow['printed'] === 0;
                $selectableCount += $selectable ? 1 : 0;
                printf('<tr><td><input class="form-check-input print-user-checkbox" type="checkbox" name="user_ids[]" value="%d" aria-label="Select user %s"%s></td>',
                       $batchRow['id'], htmlspecialchars($batchRow['username'], ENT_QUOTES, 'UTF-8'),
                       $selectable ? '' : ' disabled');
                printf('<td>%s</td><td>%s</td><td>%s</td></tr>',
                       htmlspecialchars($batchRow['username'], ENT_QUOTES, 'UTF-8'),
                       $accounted ? 'Yes' : 'No',
                       htmlspecialchars(mng_print_duration($batchRow['max_session_seconds']), ENT_QUOTES, 'UTF-8'));
            }
            echo '</tbody></table></div>';
            if ($selectableCount === 0) {
                echo '<div class="alert alert-info" role="status">There are no unaccounted, unprinted users available to print in this batch.</div>';
            }
            echo '</form>';
        }
    }

    include implode(DIRECTORY_SEPARATOR, [ $configValues['OPERATORS_INCLUDE_CONFIG'], 'logging.php' ]);
    print_footer_and_html_epilogue();
?>
