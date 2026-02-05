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
    echo json_encode(["status"=>"error","data"=>null,"error"=>"Error de conexión a la base de datos"]);
    exit();
}

$endpoint = $_GET['endpoint'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);

function response($status, $data = null, $error = null) {
    echo json_encode(["status"=>$status,"data"=>$data,"error"=>$error]);
    exit();
}

if ($endpoint == "register") {
    $nombre = $data['nombre'];
    $telefono = $data['telefono'];
    $cedula = $data['cedula'];
    $email = $data['email'];
    $password = password_hash($data['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO usuarios (nombre, telefono, cedula, email, password) VALUES (?,?,?,?,?)");
    $stmt->bind_param("sssss",$nombre,$telefono,$cedula,$email,$password);

    if ($stmt->execute()) response("success","Usuario registrado");
    response("error",null,"Email ya registrado");
}

if ($endpoint == "login") {
    $email = $data['email'];
    $password = $data['password'];

    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE email=?");
    $stmt->bind_param("s",$email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        if (!password_verify($password,$user['password'])) response("error",null,"Contraseña incorrecta");
        unset($user['password']);
        $user['tipo']="usuario";
        response("success",$user);
    }

    $stmt = $conn->prepare("SELECT * FROM admin WHERE email=?");
    $stmt->bind_param("s",$email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $admin = $res->fetch_assoc();
        if (password_get_info($admin['password'])['algo'] === null) {
            if ($password !== $admin['password']) response("error",null,"Contraseña incorrecta");
        } else {
            if (!password_verify($password,$admin['password'])) response("error",null,"Contraseña incorrecta");
        }
        unset($admin['password']);
        $admin['tipo']="admin";
        response("success",$admin);
    }

    response("error",null,"Usuario no encontrado");
}

if ($endpoint == "create-booking") {
    $usuario_id = $data['usuario_id'];
    $tipo = $data['tipo_cancha'];
    $fecha = $data['fecha'];
    $hora = $data['hora'];
    $duracion = intval($data['duracion']);
    $extra = $data['servicios_extra'];

    $stmt = $conn->prepare("SELECT hora FROM bloqueos WHERE fecha=? AND tipo_cancha=?");
    $stmt->bind_param("ss",$fecha,$tipo);
    $stmt->execute();
    $bloqueos = $stmt->get_result();

    list($h,$m) = explode(':',$hora);
    $inicio = ($h*60)+$m;
    $fin = $inicio+($duracion*60);

    while ($b = $bloqueos->fetch_assoc()) {
        if ($b['hora']==='todo') response("error",null,"Este día está bloqueado para esta cancha");
        list($bh,$bm)=explode(':',$b['hora']);
        $b_inicio = ($bh*60)+$bm;
        if ($inicio === $b_inicio) response("error",null,"Horario bloqueado para esta cancha");
    }

    $stmt = $conn->prepare("SELECT 1 FROM torneos WHERE fecha_inicio=? AND tipo_cancha=?");
    $stmt->bind_param("ss",$fecha,$tipo);
    $stmt->execute();
    if ($stmt->get_result()->num_rows>0) response("error",null,"Hay un torneo en esta cancha ese día");

    $stmt = $conn->prepare("SELECT hora,duracion FROM reservas WHERE fecha=? AND tipo_cancha=?");
    $stmt->bind_param("ss",$fecha,$tipo);
    $stmt->execute();
    $reservas = $stmt->get_result();

    while ($reserva = $reservas->fetch_assoc()) {
        list($rh,$rm)=explode(':',$reserva['hora']);
        $res_inicio = ($rh*60)+$rm;
        $res_fin = $res_inicio+(intval($reserva['duracion'])*60);
        if ($inicio < $res_fin && $fin > $res_inicio) {
            response("error",null,"Conflicto: existe una reserva desde {$reserva['hora']} por {$reserva['duracion']}h");
        }
    }

    $precios=["futbol5"=>80000,"futbol7"=>120000,"futbol11"=>200000];
    $extras=["ninguno"=>0,"chalecos"=>10000,"balon"=>15000,"arbitro"=>50000,"todo"=>70000];

    $precio_total = ($precios[$tipo]*$duracion)+$extras[$extra];

    $stmt = $conn->prepare("INSERT INTO reservas (usuario_id,tipo_cancha,fecha,hora,duracion,servicios_extra,precio_total) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("isssisd",$usuario_id,$tipo,$fecha,$hora,$duracion,$extra,$precio_total);

    if ($stmt->execute()) response("success","Reserva creada correctamente");
    response("error",null,"Error al crear la reserva");
}

if ($endpoint == "get-bookings") {
    $usuario_id = $_GET['usuario_id'];
    $stmt = $conn->prepare("SELECT * FROM reservas WHERE usuario_id=?");
    $stmt->bind_param("i",$usuario_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $out=[];
    while($r=$res->fetch_assoc()) $out[]=$r;
    response("success",$out);
}

if ($endpoint == "cancel-booking") {
    $reserva_id = $data['reserva_id'];
    $stmt = $conn->prepare("DELETE FROM reservas WHERE id=?");
    $stmt->bind_param("i",$reserva_id);
    if ($stmt->execute()) response("success","Reserva cancelada");
    response("error",null,"No se pudo cancelar");
}

if ($endpoint == "stats") {
    $users = $conn->query("SELECT COUNT(*) total FROM usuarios")->fetch_assoc()['total'];
    $bookings = $conn->query("SELECT COUNT(*) total FROM reservas")->fetch_assoc()['total'];
    $revenue = $conn->query("SELECT SUM(precio_total) total FROM reservas")->fetch_assoc()['total'] ?? 0;
    $torneos = $conn->query("SELECT COUNT(*) total FROM torneos")->fetch_assoc()['total'] ?? 0;

    response("success",[
        "users"=>(int)$users,
        "bookings"=>(int)$bookings,
        "revenue"=>(float)$revenue,
        "tournaments"=>(int)$torneos
    ]);
}

if ($endpoint == "get-all-users") {
    $res=$conn->query("SELECT id,nombre,email,telefono,cedula FROM usuarios");
    $out=[];
    while($r=$res->fetch_assoc()) $out[]=$r;
    response("success",$out);
}

if ($endpoint == "get-all-bookings") {
    $res=$conn->query("SELECT r.*,u.nombre userName FROM reservas r JOIN usuarios u ON r.usuario_id=u.id ORDER BY r.fecha DESC,r.hora DESC");
    $out=[];
    while($r=$res->fetch_assoc()) $out[]=$r;
    response("success",$out);
}

if ($endpoint == "create-block") {
    $fecha=$data['fecha'];
    $hora=$data['hora'];
    $tipo=$data['tipo_cancha'];
    $motivo=$data['motivo'] ?? 'Mantenimiento';

    $stmt=$conn->prepare("SELECT 1 FROM bloqueos WHERE fecha=? AND hora=? AND tipo_cancha=?");
    $stmt->bind_param("sss",$fecha,$hora,$tipo);
    $stmt->execute();
    if ($stmt->get_result()->num_rows>0) response("error",null,"Ya existe un bloqueo");

    $stmt=$conn->prepare("INSERT INTO bloqueos (fecha,hora,tipo_cancha,motivo) VALUES (?,?,?,?)");
    $stmt->bind_param("ssss",$fecha,$hora,$tipo,$motivo);
    if ($stmt->execute()) response("success","Bloqueo creado");
    response("error",null,"Error al crear bloqueo");
}

if ($endpoint == "get-blocks") {
    $res=$conn->query("SELECT * FROM bloqueos ORDER BY fecha DESC,hora ASC");
    $out=[];
    while($r=$res->fetch_assoc()) $out[]=$r;
    response("success",$out);
}

if ($endpoint == "delete-block") {
    $id=$data['bloqueo_id'];
    $stmt=$conn->prepare("DELETE FROM bloqueos WHERE id=?");
    $stmt->bind_param("i",$id);
    if ($stmt->execute()) response("success","Bloqueo eliminado");
    response("error",null,"Error al eliminar bloqueo");
}

if ($endpoint == "check-availability") {
    $fecha=$_GET['fecha'];
    $hora=$_GET['hora'] ?? null;
    $tipo=$_GET['tipo_cancha'];

    if ($hora) {
        $stmt=$conn->prepare("SELECT 1 FROM bloqueos WHERE fecha=? AND tipo_cancha=? AND (hora=? OR hora='todo')");
        $stmt->bind_param("sss",$fecha,$tipo,$hora);
    } else {
        $stmt=$conn->prepare("SELECT 1 FROM bloqueos WHERE fecha=? AND tipo_cancha=?");
        $stmt->bind_param("ss",$fecha,$tipo);
    }

    $stmt->execute();
    $bloqueado=$stmt->get_result()->num_rows>0;

    response("success",["disponible"=>!$bloqueado,"bloqueado"=>$bloqueado]);
}
?>