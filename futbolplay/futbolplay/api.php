<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

$host = "localhost";
$user = "root";
$password = "";
$db = "futbolplay";

$conn = new mysqli($host, $user, $password, $db);

if ($conn->connect_error) {
    echo json_encode(["status"=>"error","data"=>null,
        "error"=>"Error de conexión a la base de datos"]);
    exit();
}

$endpoint = $_GET['endpoint'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);

function response($status, $data = null, $error = null) {
    echo json_encode([
        "status" => $status,
        "data" => $data,
        "error" => $error
    ]);
    exit();
}



if ($endpoint == "register") {
    $nombre = $data['nombre'];
    $telefono = $data['telefono'];
    $cedula = $data['cedula'];
    $email = $data['email'];
    $password = password_hash($data['password'], PASSWORD_DEFAULT);

    $sql = "INSERT INTO usuarios (nombre, telefono, cedula, email, password)
            VALUES ('$nombre','$telefono','$cedula','$email','$password')";

    if ($conn->query($sql)) {
        response("success", "Usuario registrado");
    } else {
        response("error", null, "Email ya registrado");
    }
}

if ($endpoint == "login") {
    $email = $data['email'];
    $password = $data['password'];

    
    $sql = "SELECT * FROM usuarios WHERE email='$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        if (!password_verify($password, $user['password'])) {
            response("error", null, "Contraseña incorrecta");
        }
        
        unset($user['password']);
        $user['tipo'] = 'usuario'; 
        response("success", $user);
    }


    $sql = "SELECT * FROM admin WHERE email='$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $admin = $result->fetch_assoc();
        
        // VERIFICAR SI LA CONTRASEÑA ESTÁ HASHEADA O ES TEXTO PLANO
        if (password_get_info($admin['password'])['algo'] === null) {
            // Es texto plano, comparar directamente
            if ($password !== $admin['password']) {
                response("error", null, "Contraseña incorrecta");
            }
        } else {
            // Es hash, usar password_verify
            if (!password_verify($password, $admin['password'])) {
                response("error", null, "Contraseña incorrecta");
            }
        }
        
        unset($admin['password']);
        $admin['tipo'] = 'admin'; // Marcamos que es admin
        response("success", $admin);
    }

    // Si no se encontró ni en usuarios ni en admin
    response("error", null, "Usuario no encontrado");
}

/* CREAR RESERVA - CON VALIDACIÓN DE BLOQUEOS, RESERVAS Y DURACIÓN */
if ($endpoint == "create-booking") {
    $usuario_id = $data['usuario_id'];
    $tipo = $data['tipo_cancha'];
    $fecha = $data['fecha'];
    $hora = $data['hora'];
    $duracion = intval($data['duracion']);
    $extra = $data['servicios_extra'];

    // VALIDAR SI LA FECHA/HORA ESTÁ BLOQUEADA
    $check_sql = "SELECT * FROM bloqueos WHERE fecha='$fecha' AND (hora='$hora' OR hora='todo')";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        response("error", null, "Este horario está bloqueado. Por favor selecciona otro.");
    }

    // ⭐ NUEVA VALIDACIÓN: Verificar si hay un torneo ese día
    $check_torneo = "SELECT * FROM torneos WHERE fecha_inicio='$fecha'";
    $torneo_result = $conn->query($check_torneo);
    
    if ($torneo_result->num_rows > 0) {
        response("error", null, "No se pueden hacer reservas este día porque hay un torneo programado.");
    }

    // ⭐ VALIDACIÓN MEJORADA: Verificar conflictos de horarios considerando duración
    // Convertir hora a número (ej: "14:00" -> 14)
    $hora_inicio = intval(explode(':', $hora)[0]);
    $hora_fin = $hora_inicio + $duracion;
    
    // Buscar todas las reservas en la misma fecha
    $reservas_dia = $conn->query("SELECT hora, duracion FROM reservas WHERE fecha='$fecha'");
    
    while ($reserva = $reservas_dia->fetch_assoc()) {
        $reserva_inicio = intval(explode(':', $reserva['hora'])[0]);
        $reserva_fin = $reserva_inicio + intval($reserva['duracion']);
        
        // Verificar si hay solapamiento
        // Caso 1: La nueva reserva comienza durante una existente
        // Caso 2: La nueva reserva termina durante una existente
        // Caso 3: La nueva reserva engloba una existente
        $hay_conflicto = ($hora_inicio >= $reserva_inicio && $hora_inicio < $reserva_fin) ||
                        ($hora_fin > $reserva_inicio && $hora_fin <= $reserva_fin) ||
                        ($hora_inicio <= $reserva_inicio && $hora_fin >= $reserva_fin);
        
        if ($hay_conflicto) {
            response("error", null, "Hay un conflicto de horarios. Esta reserva se solapa con otra existente (hora: {$reserva['hora']}, duración: {$reserva['duracion']}h).");
        }
    }

    $precios = [
        "futbol5" => 80000,
        "futbol7" => 120000,
        "futbol11" => 200000
    ];

    $extras = [
        "ninguno" => 0,
        "chalecos" => 10000,
        "balon" => 15000,
        "arbitro" => 50000,
        "todo" => 70000
    ];

    $precio_total = ($precios[$tipo] * $duracion) + $extras[$extra];

    $sql = "INSERT INTO reservas
            (usuario_id, tipo_cancha, fecha, hora, duracion, servicios_extra, precio_total)
            VALUES
            ('$usuario_id','$tipo','$fecha','$hora','$duracion','$extra','$precio_total')";

    if ($conn->query($sql)) {
        response("success", "Reserva creada");
    } else {
        response("error", null, "Error al crear reserva");
    }
}


if ($endpoint == "get-bookings") {
    $usuario_id = $_GET['usuario_id'];

    $sql = "SELECT * FROM reservas WHERE usuario_id='$usuario_id'";
    $result = $conn->query($sql);

    $reservas = [];
    while ($row = $result->fetch_assoc()) {
        $reservas[] = $row;
    }

    response("success", $reservas);
}


if ($endpoint == "cancel-booking") {
    $reserva_id = $data['reserva_id'];

    $sql = "DELETE FROM reservas WHERE id='$reserva_id'";

    if ($conn->query($sql)) {
        response("success", "Reserva cancelada");
    } else {
        response("error", null, "No se pudo cancelar");
    }
}


if ($endpoint == "create-tournament") {
    $usuario_id = $data['usuario_id'];
    $nombre = $data['nombre'];
    $tipo_cancha = $data['tipo_cancha'];
    $fecha_inicio = $data['fecha_inicio'];
    $num_equipos = $data['num_equipos'];
    $premio = $data['premio'];
    $costo_organizacion = $data['costo_organizacion'];

    // ⭐ VALIDACIÓN 1: Verificar si ya hay un torneo ese día
    $check_torneo = "SELECT * FROM torneos WHERE fecha_inicio='$fecha_inicio'";
    $torneo_result = $conn->query($check_torneo);
    
    if ($torneo_result->num_rows > 0) {
        response("error", null, "Ya existe un torneo programado para esta fecha. Por favor selecciona otro día.");
    }

    // ⭐ VALIDACIÓN 2: Verificar si hay reservas ese día
    $check_reservas = "SELECT * FROM reservas WHERE fecha='$fecha_inicio'";
    $reservas_result = $conn->query($check_reservas);
    
    if ($reservas_result->num_rows > 0) {
        response("error", null, "No se puede crear un torneo este día porque ya hay reservas programadas.");
    }

    // ⭐ VALIDACIÓN 3: Verificar si el día está bloqueado
    $check_bloqueo = "SELECT * FROM bloqueos WHERE fecha='$fecha_inicio'";
    $bloqueo_result = $conn->query($check_bloqueo);
    
    if ($bloqueo_result->num_rows > 0) {
        response("error", null, "Este día está bloqueado. No se puede crear un torneo.");
    }

    $sql = "INSERT INTO torneos (usuario_id, nombre, tipo_cancha, fecha_inicio, num_equipos, premio, costo_organizacion)
            VALUES ('$usuario_id', '$nombre', '$tipo_cancha', '$fecha_inicio', '$num_equipos', '$premio', '$costo_organizacion')";

    if ($conn->query($sql)) {
        response("success", "Torneo creado exitosamente");
    } else {
        response("error", null, "Error al crear torneo: " . $conn->error);
    }
}

if ($endpoint == "get-tournaments") {
    $usuario_id = $_GET['usuario_id'];

    $sql = "SELECT * FROM torneos WHERE usuario_id='$usuario_id' ORDER BY fecha_inicio DESC";
    $result = $conn->query($sql);

    $torneos = [];
    while ($row = $result->fetch_assoc()) {
        $torneos[] = $row;
    }

    response("success", $torneos);
}

if ($endpoint == "get-all-tournaments") {
    $sql = "SELECT t.*, u.nombre AS organizador
            FROM torneos t
            JOIN usuarios u ON t.usuario_id = u.id
            ORDER BY t.fecha_inicio DESC";

    $result = $conn->query($sql);

    $torneos = [];
    while ($row = $result->fetch_assoc()) {
        $torneos[] = $row;
    }

    response("success", $torneos);
}

if ($endpoint == "delete-tournament") {
    $torneo_id = $data['torneo_id'];
    $usuario_id = $data['usuario_id'];

    
    $sql = "DELETE FROM torneos WHERE id='$torneo_id' AND usuario_id='$usuario_id'";

    if ($conn->query($sql)) {
        if ($conn->affected_rows > 0) {
            response("success", "Torneo eliminado");
        } else {
            response("error", null, "No tienes permiso para eliminar este torneo");
        }
    } else {
        response("error", null, "Error al eliminar torneo");
    }
}

if ($endpoint == "delete-tournament-admin") {
    $torneo_id = $data['torneo_id'];

    $sql = "DELETE FROM torneos WHERE id='$torneo_id'";

    if ($conn->query($sql)) {
        if ($conn->affected_rows > 0) {
            response("success", "Torneo eliminado exitosamente");
        } else {
            response("error", null, "Torneo no encontrado");
        }
    } else {
        response("error", null, "Error al eliminar torneo: " . $conn->error);
    }
}


if ($endpoint == "stats") {

    $users = $conn->query("SELECT COUNT(*) AS total FROM usuarios")
                  ->fetch_assoc()['total'];

    $bookings = $conn->query("SELECT COUNT(*) AS total FROM reservas")
                     ->fetch_assoc()['total'];

    $revenue = $conn->query("SELECT SUM(precio_total) AS total FROM reservas")
                    ->fetch_assoc()['total'] ?? 0;

    $torneos = $conn->query("SELECT COUNT(*) AS total FROM torneos")
                    ->fetch_assoc()['total'] ?? 0;

    response("success", [
        "users" => (int)$users,
        "bookings" => (int)$bookings,
        "revenue" => (float)$revenue,
        "tournaments" => (int)$torneos
    ]);
}

/* OBTENER TODOS LOS USUARIOS (ADMIN) */
if ($endpoint == "get-all-users") {

    $sql = "SELECT id, nombre, email, telefono, cedula FROM usuarios";
    $result = $conn->query($sql);

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    response("success", $users);
}
if ($endpoint == "get-all-bookings") {

    $sql = "SELECT r.*, u.nombre AS userName
            FROM reservas r
            JOIN usuarios u ON r.usuario_id = u.id
            ORDER BY r.fecha DESC, r.hora DESC";

    $result = $conn->query($sql);

    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }

    response("success", $bookings);
}


if ($endpoint == "create-block") {
    $fecha = $data['fecha'];
    $hora = $data['hora'];
    $motivo = $data['motivo'] ?? 'Mantenimiento';

    $check = "SELECT * FROM bloqueos WHERE fecha='$fecha' AND hora='$hora'";
    $result = $conn->query($check);
    
    if ($result->num_rows > 0) {
        response("error", null, "Ya existe un bloqueo para esta fecha y hora");
    }

    $sql = "INSERT INTO bloqueos (fecha, hora, motivo) VALUES ('$fecha', '$hora', '$motivo')";

    if ($conn->query($sql)) {
        response("success", "Bloqueo creado exitosamente");
    } else {
        response("error", null, "Error al crear bloqueo: " . $conn->error);
    }
}


if ($endpoint == "get-blocks") {
    $sql = "SELECT * FROM bloqueos ORDER BY fecha DESC, hora ASC";
    $result = $conn->query($sql);

    $bloqueos = [];
    while ($row = $result->fetch_assoc()) {
        $bloqueos[] = $row;
    }

    response("success", $bloqueos);
}


if ($endpoint == "delete-block") {
    $bloqueo_id = $data['bloqueo_id'];

    $sql = "DELETE FROM bloqueos WHERE id='$bloqueo_id'";

    if ($conn->query($sql)) {
        response("success", "Bloqueo eliminado");
    } else {
        response("error", null, "Error al eliminar bloqueo");
    }
}
if ($endpoint == "check-availability") {
    $fecha = $_GET['fecha'];
    $hora = $_GET['hora'] ?? null;

    if ($hora) {
        
        $sql = "SELECT * FROM bloqueos WHERE fecha='$fecha' AND (hora='$hora' OR hora='todo')";
    } else {
       
        $sql = "SELECT * FROM bloqueos WHERE fecha='$fecha'";
    }

    $result = $conn->query($sql);
    $bloqueado = $result->num_rows > 0;

    response("success", ["disponible" => !$bloqueado, "bloqueado" => $bloqueado]);
}

?>