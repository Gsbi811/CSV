<?php
// Aumentar el límite de tiempo de ejecución y memoria para archivos grandes
set_time_limit(300); // 5 minutos de tiempo de ejecución
ini_set('memory_limit', '512M'); // Límite de memoria

$upload_dir = 'uploads/'; 
$output_filename = 'export_vehiculos_fusionado_' . time() . '.csv';
$output_file = $upload_dir . $output_filename; 

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
// FUNCIÓN CRÍTICA DE LECTURA: Normalización AGRESIVA de Encabezados y Codificación
// =========================================================================
/**
 * Lee el archivo, convierte su contenido a UTF-8, detecta el delimitador 
 * y normaliza los encabezados (minúsculas, sin acentos).
 */
function get_csv_file_info($filepath) {
    // 1. Leer todo el contenido del archivo
    $content = file_get_contents($filepath);
    if ($content === FALSE) return false;

    // 2. Intentar la conversión a UTF-8 (Robustez ante codificaciones latinas)
    // Se asume ISO-8859-1 (latin1) como la fuente más probable si no es UTF-8.
    $converted_content = @iconv("ISO-8859-1", "UTF-8//IGNORE", $content);
    if ($converted_content !== FALSE && $converted_content !== $content) {
        $content = $converted_content;
    }
    
    // 3. Obtener la primera línea y el contenido de datos
    $lines = explode("\n", $content);
    $first_line = array_shift($lines);
    $content_data_only = implode("\n", $lines); 

    // 4. Limpiar el Byte Order Mark (BOM) si existe
    if (substr($first_line, 0, 3) == "\xef\xbb\xbf") {
        $first_line = substr($first_line, 3);
    }
    
    // 5. Detección de delimitador: preferencia a punto y coma (;)
    $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
    
    // 6. Parsear la línea
    $original_headers = str_getcsv($first_line, $delimiter);
    
    // TABLA DE REEMPLAZO para acentos y eñes
    $normalize_chars = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'ñ' => 'n', 'Ñ' => 'N',
    ];

    // Limpieza y NORMALIZACIÓN AGRESIVA:
    $cleaned_headers = array_map(function($h) use ($normalize_chars) {
        // 1. Eliminar tildes y eñes
        $h = strtr($h, $normalize_chars);
        // 2. Quitar espacios y saltos de línea
        $h = trim(str_replace(["\r", "\n"], '', $h));
        // 3. Convertir a minúsculas
        $h = strtolower($h);
        return $h;
    }, $original_headers);

    return [
        'content' => $content_data_only,
        'cleaned_headers' => $cleaned_headers, // Usado para buscar (siempre sin tildes)
        'original_headers' => $original_headers, // Usado para escribir la cabecera del archivo de salida
        'delimiter' => $delimiter
    ];
}

// =========================================================================
// Función para manejar la subida (copia del código anterior)
// =========================================================================
function handle_upload($file_key, $upload_dir, &$error) {
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == UPLOAD_ERR_OK) {
        $tmp_name = $_FILES[$file_key]['tmp_name'];
        $target_file = $upload_dir . uniqid() . '-' . basename($_FILES[$file_key]['name']) . '.csv';
        
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
            
            // Procesamos el contenido del Listado B (ya sin encabezados) línea por línea
            $listado_rows = str_getcsv_lines($info_listado['content'], $delimiter_listado);

            // Encontrar índices de las columnas en Listado B (usando encabezados NORMALIZADOS sin tildes)
            $key_index_b = array_search('codvehiculo', $headers_listado_cleaned);
            $matricula_index_b = array_search('matricula', $headers_listado_cleaned);
            $bastidor_index_b = array_search('bastidor', $headers_listado_cleaned);
            $fechasalida_index_b = array_search('fechasalida', $headers_listado_cleaned);

            if ($key_index_b === FALSE) {
                throw new Exception("El archivo Listado B no contiene la columna 'codvehiculo'. Encabezados detectados: " . implode(' | ', $headers_listado_cleaned));
            }

            // Llenar el mapa de Listado B
            foreach ($listado_rows as $row) {
                if (empty(array_filter($row))) continue; 
                
                if (isset($row[$key_index_b])) {
                    // LIMPIEZA CRÍTICA DE LA CLAVE: trim() para eliminar espacios
                    $key = trim($row[$key_index_b]);
                    
                    // Almacenar solo las columnas necesarias (limpiando espacios alrededor de los valores)
                    $listado_map[$key] = [
                        'matricula' => trim($row[$matricula_index_b] ?? ''),
                        'bastidor' => trim($row[$bastidor_index_b] ?? ''),
                        'fechasalida' => trim($row[$fechasalida_index_b] ?? '')
                    ];
                }
            }
            
            
            // --- 3. FUSIÓN: Procesar Export Vehículos y escribir el resultado ---

            $handle_output = fopen($output_file, "w");
            if ($handle_output === FALSE) throw new Exception("Error al abrir archivos para escritura.");

            fputcsv($handle_output, $headers_export_original, ','); // Escribir encabezados ORIGINALES con COMA estándar

            // Encontrar índices en Export Vehículos para la ACTUALIZACIÓN
            $key_index_export = array_search('codvehiculo', $headers_export_cleaned); 
            $matricula_index_export = array_search('matricula', $headers_export_cleaned); 
            $bastidor_index_export = array_search('bastidor', $headers_export_cleaned);
            $fechasalida_index_export = array_search('fechasalida', $headers_export_cleaned);
            
            if ($key_index_export === FALSE) throw new Exception("El archivo Export Vehículos no contiene la columna 'codvehiculo'.");

            // Procesar el contenido de Export Vehículos (ya sin encabezados) línea por línea
            $export_rows = str_getcsv_lines($info_export['content'], $delimiter_export);

            // Procesar fila por fila (LEFT JOIN LÓGICO)
            foreach ($export_rows as $row) {
                if (empty(array_filter($row))) continue; 
                
                $processed_rows++;
                
                $key = trim($row[$key_index_export]); 

                if (isset($listado_map[$key])) {
                    $match = $listado_map[$key];
                    $merged_rows++;
                    
                    // LÓGICA DE ACTUALIZACIÓN (Solo si el índice existe y el valor no está vacío)
                    if ($matricula_index_export !== FALSE && !empty($match['matricula'])) {
                        $row[$matricula_index_export] = $match['matricula'];
                    }
                    if ($bastidor_index_export !== FALSE && !empty($match['bastidor'])) {
                        $row[$bastidor_index_export] = $match['bastidor'];
                    }
                    if ($fechasalida_index_export !== FALSE && !empty($match['fechasalida'])) {
                        $row[$fechasalida_index_export] = $match['fechasalida'];
                    }
                }
                
                fputcsv($handle_output, $row, ','); // Escribir la fila con COMA como delimitador estándar
            }
            
            fclose($handle_output);
            
            // --- 4. DESCARGA ---
            
            if (file_exists($output_file)) {
                // Headers para forzar la descarga
                header('Content-Description: File Transfer');
                header('Content-Type: application/csv');
                header('Content-Disposition: attachment; filename="' . $output_filename . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($output_file));
                ob_clean();
                flush();
                readfile($output_file);
                
                // Limpieza de archivos temporales
                unlink($file_export_path);
                unlink($file_listado_path);
                unlink($output_file);

                exit;
            } else {
                throw new Exception("El archivo de salida no se pudo generar.");
            }

        } catch (Exception $e) {
            $error = "Error durante el proceso de fusión: " . $e->getMessage();
        }
    } else {
        $error = "Fallo en la subida de uno o ambos archivos: " . $error;
    }
    
    // Limpieza de archivos temporales si el proceso falló antes de la descarga
    if (file_exists($file_export_path)) unlink($file_export_path);
    if (file_exists($file_listado_path)) unlink($file_listado_path);
    if (file_exists($output_file)) unlink($output_file);

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

// Mostrar el mensaje de error o éxito si la descarga no se pudo iniciar
echo "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><title>Resultado de Fusión</title></head>
<body>
    <div class='container' style='text-align: center; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #ccc; border-radius: 8px;'>
        <h1>Resultado del Proceso</h1>
        " . ($error ? "<p style='color: red; font-weight: bold;'>❌ ERROR: {$error}</p>" : 
                       "<p style='color: green; font-weight: bold;'>✅ Proceso de fusión completado. Se procesaron {$processed_rows} filas y se actualizaron {$merged_rows} registros. (Verifique si la descarga se inició automáticamente).</p>") . "
        <p><a href='index.html'>Volver al formulario</a></p>
    </div>
</body>
</html>";
?>