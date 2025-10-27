<?php
// Aumentar el límite de tiempo de ejecución y memoria para archivos grandes
set_time_limit(300); // 5 minutos de tiempo de ejecución
ini_set('memory_limit', '512M'); // Límite de memoria

$upload_dir = 'uploads/'; 
$timestamp = time();
// Nombres de archivo de salida
$output_filename_comma = 'export_vehiculos_fusionado_COMMA_' . $timestamp . '.csv';
$output_filename_semicolon = 'export_vehiculos_fusionado_SEMICOLON_' . $timestamp . '.csv';
$output_file_comma = $upload_dir . $output_filename_comma; 
$output_file_semicolon = $upload_dir . $output_filename_semicolon;

// Definir qué archivo se descargará automáticamente y cuál tendrá un enlace
$download_file = $output_file_semicolon;
$download_filename = $output_filename_semicolon;
$link_file = $output_file_comma;
$link_filename = $output_filename_comma;


// Asegurar que el directorio de subida existe
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Variables para rutas de archivos subidos y mensaje de error
$file_export_path = '';
$file_listado_path = '';
$error = '';
$processed_rows = 0;
$merged_rows = 0;

// =========================================================================
// FUNCIÓN CRÍTICA DE LECTURA MEJORADA: Codificación Robusta a UTF-8
// =========================================================================
/**
 * Lee el archivo, convierte su contenido a UTF-8 de forma robusta, detecta el delimitador 
 * y normaliza los encabezados (minúsculas, sin acentos) para la búsqueda.
 */
function get_csv_file_info($filepath) {
    // 1. Leer todo el contenido del archivo
    $content = file_get_contents($filepath);
    if ($content === FALSE) return false;

    // 2. Limpiar el Byte Order Mark (BOM) si existe y si el archivo ya está en UTF-8
    if (substr($content, 0, 3) == "\xef\xbb\xbf") {
        $content = substr($content, 3);
    }
    
    // 3. Conversión de Codificación a UTF-8 (Robustez ante codificaciones latinas)
    $source_encodings = ['ISO-8859-1', 'Windows-1252', 'UTF-8'];
    $converted_content = $content;

    if (!mb_check_encoding($content, 'UTF-8')) {
        foreach ($source_encodings as $encoding) {
            $test_conversion = @iconv($encoding, "UTF-8//IGNORE", $content);
            if ($test_conversion !== FALSE) {
                $converted_content = $test_conversion;
                break;
            }
        }
    }
    
    // 4. Obtener la primera línea y el contenido de datos
    $lines = explode("\n", $converted_content);
    $first_line = array_shift($lines);
    $content_data_only = implode("\n", $lines); 
    
    // 5. Detección de delimitador: preferencia a punto y coma (;)
    $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
    
    // 6. Parsear la línea de encabezados
    $original_headers = str_getcsv($first_line, $delimiter);
    
    // TABLA DE REEMPLAZO para normalizar (quitar acentos y eñes)
    $normalize_chars = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U',
    ];

    // Limpieza y NORMALIZACIÓN: Minúsculas y sin acentos (SOLO para claves de búsqueda)
    $cleaned_headers = array_map(function($h) use ($normalize_chars) {
        $h = strtr($h, $normalize_chars);
        $h = trim(str_replace(["\r", "\n"], '', $h));
        $h = strtolower($h);
        return $h;
    }, $original_headers);

    return [
        'content' => $content_data_only,
        'cleaned_headers' => $cleaned_headers, // Usado para buscar
        'original_headers' => $original_headers, // Usado para escribir la cabecera
        'delimiter' => $delimiter
    ];
}

// =========================================================================
// Función para manejar la subida (sin cambios)
// =========================================================================
function handle_upload($file_key, $upload_dir, &$error) {
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == UPLOAD_ERR_OK) {
        $tmp_name = $_FILES[$file_key]['tmp_name'];
        // Usar hash para evitar nombres de archivo demasiado largos
        $target_file = $upload_dir . hash('sha256', uniqid('', true)) . '.csv'; 
        
        if (move_uploaded_file($tmp_name, $target_file)) {
            return $target_file;
        } else {
            $error .= "Error al mover el archivo '{$file_key}'. ";
            return false;
        }
    } else {
        if (isset($_FILES[$file_key]['error']) && $_FILES[$file_key]['error'] != UPLOAD_ERR_NO_FILE) {
            $error .= "Error en la subida del archivo '{$file_key}'. Código: " . $_FILES[$file_key]['error'] . ". ";
        } else {
             $error .= "Archivo '{$file_key}' no subido. ";
        }
        return false;
    }
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $file_export_path = handle_upload('export_vehiculos', $upload_dir, $error);
    $file_listado_path = handle_upload('listado_b', $upload_dir, $error);

    if ($file_export_path && $file_listado_path) {
        
        try {
            // --- 1. PREPARACIÓN: Leer y convertir el contenido ---
            
            $info_export = get_csv_file_info($file_export_path);
            $info_listado = get_csv_file_info($file_listado_path);

            if ($info_export === false || $info_listado === false) {
                 throw new Exception("Error al leer los archivos CSV o al convertir la codificación.");
            }
            
            $headers_export_cleaned = $info_export['cleaned_headers'];
            $headers_export_original = $info_export['original_headers'];
            $delimiter_export = $info_export['delimiter'];

            $headers_listado_cleaned = $info_listado['cleaned_headers'];
            $delimiter_listado = $info_listado['delimiter'];
            
            // --- 2. Crear mapa de listado B para búsqueda rápida ---
            
            $listado_map = [];
            
            $listado_rows = str_getcsv_lines($info_listado['content'], $delimiter_listado);

            $key_index_b = array_search('codvehiculo', $headers_listado_cleaned);
            $matricula_index_b = array_search('matricula', $headers_listado_cleaned);
            $bastidor_index_b = array_search('bastidor', $headers_listado_cleaned);
            $fechasalida_index_b = array_search('fechasalida', $headers_listado_cleaned);

            if ($key_index_b === FALSE) {
                throw new Exception("El archivo Listado B no contiene la columna 'codvehiculo'. Encabezados detectados: " . implode(' | ', $headers_listado_cleaned));
            }

            foreach ($listado_rows as $row) {
                if (empty(array_filter($row))) continue; 
                
                if (isset($row[$key_index_b])) {
                    $key = trim($row[$key_index_b]);
                    
                    $listado_map[$key] = [
                        'matricula' => trim($row[$matricula_index_b] ?? ''),
                        'bastidor' => trim($row[$bastidor_index_b] ?? ''),
                        'fechasalida' => trim($row[$fechasalida_index_b] ?? '')
                    ];
                }
            }
            
            
            // --- 3. FUSIÓN y ESCRITURA: Procesar Export Vehículos y escribir los DOS resultados ---

            // Abrir ambos archivos de salida
            $handle_output_comma = fopen($output_file_comma, "w");
            $handle_output_semicolon = fopen($output_file_semicolon, "w");
            
            if ($handle_output_comma === FALSE || $handle_output_semicolon === FALSE) {
                throw new Exception("Error al abrir archivos para escritura.");
            }

            // Añadir Byte Order Mark (BOM) a ambos para forzar UTF-8
            fwrite($handle_output_comma, "\xEF\xBB\xBF");
            fwrite($handle_output_semicolon, "\xEF\xBB\xBF");

            // Escribir encabezados ORIGINALES
            fputcsv($handle_output_comma, $headers_export_original, ','); 
            fputcsv($handle_output_semicolon, $headers_export_original, ';'); // Usar ';' para Excel

            // Encontrar índices en Export Vehículos para la ACTUALIZACIÓN
            $key_index_export = array_search('codvehiculo', $headers_export_cleaned); 
            $matricula_index_export = array_search('matricula', $headers_export_cleaned); 
            $bastidor_index_export = array_search('bastidor', $headers_export_cleaned);
            $fechasalida_index_export = array_search('fechasalida', $headers_export_cleaned);
            
            if ($key_index_export === FALSE) throw new Exception("El archivo Export Vehículos no contiene la columna 'codvehiculo'.");

            $export_rows = str_getcsv_lines($info_export['content'], $delimiter_export);
            
            // Establecer el límite de fecha para la comparación
            $limite_fecha_str = '2019-01-01'; // Usar formato YYYY-MM-DD
            $timestamp_limite = strtotime($limite_fecha_str);
            
            if ($timestamp_limite === FALSE) {
                 throw new Exception("Error al procesar la fecha límite de comparación.");
            }

            // Procesar fila por fila (LEFT JOIN LÓGICO)
            foreach ($export_rows as $row) {
                if (empty(array_filter($row))) continue; 
                
                $processed_rows++;
                
                $key = trim($row[$key_index_export]); 
                
                // Inicializar el valor de la fecha de salida a comprobar (el original del export)
                $current_fechasalida = ($fechasalida_index_export !== FALSE) 
                                         ? ($row[$fechasalida_index_export] ?? '') 
                                         : '';

                if (isset($listado_map[$key])) {
                    $match = $listado_map[$key];
                    $merged_rows++;
                    
                    // LÓGICA DE ACTUALIZACIÓN: Se actualizan los campos en $row
                    if ($matricula_index_export !== FALSE && !empty($match['matricula'])) {
                        $row[$matricula_index_export] = $match['matricula'];
                    }
                    if ($bastidor_index_export !== FALSE && !empty($match['bastidor'])) {
                        $row[$bastidor_index_export] = $match['bastidor'];
                    }
                    
                    // Si el Listado B tiene una fechasalida, se actualiza y ese es el valor a comprobar
                    if ($fechasalida_index_export !== FALSE && !empty($match['fechasalida'])) {
                        $row[$fechasalida_index_export] = $match['fechasalida'];
                        $current_fechasalida = $match['fechasalida']; 
                    }
                }
                
                // ====================================================================
                //  ✨ LÓGICA DE FECHA: Vaciar 'fechasalida' si es anterior a '01/01/2019'
                // ====================================================================
                // Verificamos si el campo fechasalida existe en el archivo y no está vacío
                if ($fechasalida_index_export !== FALSE && !empty($current_fechasalida)) {
                    
                    // 1. Convertir la fecha de salida a timestamp
                    $timestamp_salida = strtotime($current_fechasalida);
                    
                    // 2. Realizar la comparación: Si la fecha se pudo convertir y es ANTERIOR al límite
                    if ($timestamp_salida !== FALSE && $timestamp_salida < $timestamp_limite) {
                        // Establecer el valor a vacío en el array de la fila
                        $row[$fechasalida_index_export] = '';
                    }
                }
                // ====================================================================

                // Escribir la fila en AMBOS archivos de salida
                fputcsv($handle_output_comma, $row, ','); // Delimitador COMA (LibreOffice/Estándar)
                fputcsv($handle_output_semicolon, $row, ';'); // Delimitador PUNTO Y COMA (Excel)
            }
            
            fclose($handle_output_comma);
            fclose($handle_output_semicolon);
            
            // --- 4. DESCARGA (Descarga automática del archivo SEMICOLON y enlace para el COMMA) ---
            
            if (file_exists($download_file)) {
                // Headers para forzar la descarga del primer archivo (SEMICOLON/Excel)
                header('Content-Description: File Transfer');
                header('Content-Type: application/csv');
                header('Content-Disposition: attachment; filename="' . $download_filename . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($download_file));
                
                // Limpieza de archivos temporales ANTES de la descarga
                @unlink($file_export_path);
                @unlink($file_listado_path);
                
                // La descarga de los archivos CSV y la limpieza se realiza después.
                // Es CRÍTICO que la descarga se inicie AHORA y no se imprima HTML.
                ob_clean();
                flush();
                readfile($download_file);
                
                // NOTA: Para limpiar el segundo archivo (el que no se descargó), 
                // necesitamos que el usuario regrese o se haga una limpieza en otro script.
                // Dejaremos la limpieza en el `finally` o al final del script.
                
                exit; // Terminar el script inmediatamente después de la descarga
            } else {
                throw new Exception("El archivo de salida para la descarga automática no se pudo generar.");
            }

        } catch (Exception $e) {
            $error = "Error durante el proceso de fusión: " . $e->getMessage();
            // Limpieza de los archivos de salida generados si hubo un error
            @unlink($output_file_comma);
            @unlink($output_file_semicolon);
        }
    } else {
        $error = "Fallo en la subida de uno o ambos archivos: " . $error;
    }
    
    // Limpieza de archivos de subida si el proceso falló antes de la descarga
    if (file_exists($file_export_path)) @unlink($file_export_path);
    if (file_exists($file_listado_path)) @unlink($file_listado_path);

}

// ** FUNCIÓN AUXILIAR **: Necesaria porque ya leímos el contenido completo en una cadena
function str_getcsv_lines($content, $delimiter) {
    $lines = explode("\n", $content);
    $rows = [];
    foreach ($lines as $line) {
        // Ignoramos líneas que son solo saltos de línea al final del archivo
        if (trim($line) === '') continue; 
        
        $row = str_getcsv($line, $delimiter);
        
        // str_getcsv devuelve un array con un solo elemento vacío para una línea vacía
        if ($row && !(count($row) === 1 && $row[0] === null)) { 
            $rows[] = $row;
        }
    }
    return $rows;
}

// ====================================================================
// Mostrar el mensaje de error o éxito
// ====================================================================

// La URL base para el enlace (ajusta si la carpeta 'uploads' no está al nivel del script)
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}/";
$link_url = $base_url . $upload_dir . $link_filename;

// Si no hay error, se asume que el archivo principal se descargó
$success_message = "<p style='color: green; font-weight: bold;'>✅ Proceso de fusión completado. Se procesaron {$processed_rows} filas y se actualizaron {$merged_rows} registros.</p>";
$download_info = "<p>El archivo para **Excel** (`" . htmlspecialchars($download_filename) . "`) debería haberse descargado automáticamente.</p>";
$link_info = "<p>Descargue la versión para **LibreOffice/Estándar** (separador coma) aquí: <a href='" . htmlspecialchars($link_url) . "' target='_blank' download='" . htmlspecialchars($link_filename) . "'>Descargar " . htmlspecialchars($link_filename) . "</a></p>";
$cleanup_warning = "<p style='color: orange; font-style: italic; font-size: 0.9em;'>Nota: Los archivos generados en la carpeta `uploads/` se deben limpiar periódicamente.</p>";


echo "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><title>Resultado de Fusión</title></head>
<body>
    <div class='container' style='text-align: center; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #ccc; border-radius: 8px;'>
        <h1>Resultado del Proceso</h1>
        " . ($error ? "<p style='color: red; font-weight: bold;'>❌ ERROR: {$error}</p>" : 
                       $success_message . $download_info . $link_info . $cleanup_warning) . "
        <p><a href='index.html'>Volver al formulario</a></p>
    </div>
</body>
</html>";

// Limpieza del archivo que se descargó automáticamente (esto es un intento de buena práctica, 
// pero readfile() ya lo ha enviado. Un cron job o script de limpieza es mejor para archivos en `uploads/`).
@unlink($download_file);

?>