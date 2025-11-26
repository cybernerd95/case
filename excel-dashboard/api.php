<?php
// api.php - handle upload and parse using PhpSpreadsheet
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
if($action === 'upload'){
    if(empty($_FILES['excel'])){
        echo json_encode(['success'=>false,'error'=>'No file uploaded']); exit;
    }
    $f = $_FILES['excel'];
    if($f['error']){ echo json_encode(['success'=>false,'error'=>'Upload error']); exit; }

    $tmp = $f['tmp_name'];
    $name = basename($f['name']);
    $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('xls_') . '_' . $name;
    if(!move_uploaded_file($tmp, $target)){
        // fallback: try to copy
        if(!copy($tmp, $target)){
            echo json_encode(['success'=>false,'error'=>'Could not save uploaded file']); exit;
        }
    }

    // load PhpSpreadsheet - ensure composer vendor/autoload.php exists
    $autoload = __DIR__ . '/vendor/autoload.php';
    if(!file_exists($autoload)){
        echo json_encode(['success'=>false,'error'=>'PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet']); exit;
    }
    require_once $autoload;
    use PhpOffice\PhpSpreadsheet\IOFactory;

    try{
        $spreadsheet = IOFactory::load($target);
    }catch(Exception $e){
        echo json_encode(['success'=>false,'error'=>'Failed to read workbook: ' . $e->getMessage()]); exit;
    }

    $result = ['success'=>true, 'sheets'=>[], 'sheets_order'=>[], 'metadata'=>[]];
    foreach($spreadsheet->getSheetNames() as $sheetName){
        $result['sheets_order'][] = $sheetName;
        $sheet = $spreadsheet->getSheetByName($sheetName);
        $rows = $sheet->toArray(null, true, true, true);
        // first row = headers (assume headers in first row)
        if(count($rows) < 1){ $result['sheets'][$sheetName] = ['columns'=>[], 'rows'=>[]]; continue; }
        $firstKey = array_keys($rows)[0];
        $headers = array_map('trim', array_values($rows[$firstKey]));
        $columns = $headers;
        // Build associative rows starting from row 2
        $assocRows = [];
        foreach($rows as $rKey => $row){
            if($rKey == $firstKey) continue; // skip header
            $assoc = [];
            $i = 0;
            foreach($row as $cell){
                $colName = $columns[$i] ?? ('Col'.($i+1));
                $assoc[$colName] = $cell;
                $i++;
            }
            // normalize common column keys (handle variants)
            $normalized = [];
            foreach($assoc as $k=>$v){
                $nk = trim($k);
                // unify to expected names (Month, City, City_Type, Projected_Enrollments)
                if(strtolower($nk) === 'month') $nk = 'Month';
                if(strtolower(str_replace(' ','_', $nk)) === 'city') $nk = 'City';
                if(strtolower(str_replace(' ','_', $nk)) === 'city_type' || strtolower($nk)==='city type') $nk = 'City_Type';
                if(strtolower(str_replace(' ','_', $nk)) === 'projected_enrollments' || stripos($nk,'enroll')!==false) $nk = 'Projected_Enrollments';
                $normalized[$nk] = $v;
            }
            $assocRows[] = $normalized;
        }
        $result['sheets'][$sheetName] = ['columns'=>$columns, 'rows'=>$assocRows];
    }

    // cleanup temp file
    @unlink($target);
    echo json_encode($result);
    exit;
}

// default
echo json_encode(['success'=>false,'error'=>'No action specified']);
