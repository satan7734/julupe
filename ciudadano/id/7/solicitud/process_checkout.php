<?php
// Mostrar todos los errores para debug (quitar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json'); 

// Incluir PHPMailer manualmente
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';

// Si tienes variables de entorno en 'env.php'
require 'env.php'; // solo si este archivo existe

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    // Leer JSON enviado por frontend
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        throw new Exception("Datos JSON no recibidos o inválidos.");
    }

    // Validar campos necesarios
    $campos_requeridos = ['nombre', 'email', 'calle', 'tel', 'postal', 'muni', 'carrito'];
    foreach ($campos_requeridos as $campo) {
        if (empty($data[$campo])) {
            throw new Exception("Falta el campo requerido: $campo");
        }
    }

    // Obtener IP y ubicación
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'No disponible';
    $locationData = @file_get_contents("https://ipinfo.io/{$ip}/json");
    $location = json_decode($locationData, true);

    $city = $location['city'] ?? 'Desconocida';
    $region = $location['region'] ?? 'Desconocida';
    $country = $location['country'] ?? 'Desconocido';

    $horaActual = date('Y-m-d H:i:s');

    // Guardar pedido en archivo txt
    $file = 'orders.txt';
    $totalCarrito = 0;

    $orderData = "Nombre: " . $data['nombre'] . "\n";
    $orderData .= "Correo electrónico: " . $data['email'] . "\n";
    $orderData .= "Teléfono: " . $data['tel'] . "\n";
    $orderData .= "Código Postal: " . $data['postal'] . "\n";
    $orderData .= "Municipio: " . $data['muni'] . "\n";
    $orderData .= "Calle: " . $data['calle'] . "\n";
    $orderData .= "IP del cliente: " . $ip . "\n";
    $orderData .= "Ubicación: $city, $region, $country\n";
    $orderData .= "Hora: " . $horaActual . "\n";
    $orderData .= "Carrito:\n";

    foreach ($data['carrito'] as $item) {
        $orderData .= " - " . $item['nombre'] . " - $" . number_format($item['precio'], 2) . "\n";
        $totalCarrito += $item['precio'];
    }

    $orderData .= "Total del carrito: $" . number_format($totalCarrito, 2) . "\n";
    $orderData .= "-----------------------------\n\n";

    if (!file_put_contents($file, $orderData, FILE_APPEND)) {
        throw new Exception("Error al guardar el pedido en archivo.");
    }

    // Ahora enviamos correo a Zoho con los datos del pedido

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.zoho.eu';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('ZOHO_USER');
        $mail->Password = getenv('ZOHO_PASS');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom(getenv('ZOHO_USER'), 'Formulario Web');
        $mail->addAddress(getenv('ZOHO_USER')); // Envía a ti mismo, cambia si quieres

        $mail->isHTML(true);
        $mail->Subject = 'Nuevo pedido desde el checkout';

        // Armar cuerpo del correo con los datos (puedes cambiar formato)
        $body = "<h2>Nuevo pedido recibido</h2>";
        $body .= "<p><strong>Nombre:</strong> " . htmlspecialchars($data['nombre']) . "</p>";
        $body .= "<p><strong>Email:</strong> " . htmlspecialchars($data['email']) . "</p>";
        $body .= "<p><strong>Teléfono:</strong> " . htmlspecialchars($data['tel']) . "</p>";
        $body .= "<p><strong>Calle:</strong> " . htmlspecialchars($data['calle']) . "</p>";
        $body .= "<p><strong>Municipio:</strong> " . htmlspecialchars($data['muni']) . "</p>";
        $body .= "<p><strong>Código Postal:</strong> " . htmlspecialchars($data['postal']) . "</p>";
        $body .= "<p><strong>IP Cliente:</strong> $ip</p>";
        $body .= "<p><strong>Ubicación:</strong> $city, $region, $country</p>";
        $body .= "<p><strong>Hora del pedido:</strong> $horaActual</p>";
        $body .= "<h3>Carrito:</h3><ul>";
        foreach ($data['carrito'] as $item) {
            $body .= "<li>" . htmlspecialchars($item['nombre']) . " - $" . number_format($item['precio'], 2) . "</li>";
        }
        $body .= "</ul>";
        $body .= "<p><strong>Total:</strong> $" . number_format($totalCarrito, 2) . "</p>";

        $mail->Body = $body;

        $mail->send();
    } catch (Exception $e) {
        throw new Exception("No se pudo enviar el correo: " . $mail->ErrorInfo);
    }

    // Respuesta exitosa
    echo json_encode([
        "success" => true,
        "message" => "Pedido procesado y correo enviado correctamente.",
        "ip" => $ip,
        "ubicacion" => "$city, $region, $country",
        "hora" => $horaActual,
        "total" => number_format($totalCarrito, 2)
    ]);
} catch (Exception $e) {
    // Error general
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
