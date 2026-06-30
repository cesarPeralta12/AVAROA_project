<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Código de verificación</title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f5; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f5; padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(1,94,128,0.12);">
          <!-- Header -->
          <tr>
            <td style="background-color:#015E80; padding:28px 32px; text-align:center;">
              <span style="color:#ffffff; font-size:20px; font-weight:700; letter-spacing:0.5px;">Delivery AVAROA</span>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:36px 32px 8px 32px; text-align:center;">
              <p style="margin:0 0 4px 0; color:#0f172a; font-size:18px; font-weight:700;">
                Hola{{ isset($name) && $name ? ', ' . $name : '' }} 👋
              </p>
              <p style="margin:0; color:#475569; font-size:14.5px; line-height:1.5;">
                Recibimos una solicitud para restablecer tu contraseña.<br>
                Usa el siguiente código para continuar:
              </p>
            </td>
          </tr>

          <!-- OTP box -->
          <tr>
            <td style="padding:24px 32px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="background-color:#f0f7fa; border:2px dashed #015E80; border-radius:14px; padding:22px 16px;">
                    <span style="display:block; color:#64748b; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">
                      Tu código de verificación
                    </span>
                    <span style="display:inline-block; font-family:'Courier New', Courier, monospace; font-size:38px; font-weight:800; letter-spacing:10px; color:#015E80; user-select:all;">
                      {{ $otp }}
                    </span>
                    <span style="display:block; color:#94a3b8; font-size:11.5px; margin-top:10px;">
                      Mantén presionado el código para copiarlo
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Expiry note -->
          <tr>
            <td style="padding:0 32px 28px 32px; text-align:center;">
              <p style="margin:0; color:#475569; font-size:13.5px;">
                ⏱️ Este código vence en <strong>10 minutos</strong>.
              </p>
              <p style="margin:14px 0 0 0; color:#94a3b8; font-size:12.5px; line-height:1.5;">
                Si no solicitaste este cambio, podés ignorar este correo —
                tu contraseña actual seguirá funcionando sin problemas.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f8fafc; padding:18px 32px; text-align:center; border-top:1px solid #e2e8f0;">
              <p style="margin:0; color:#94a3b8; font-size:11.5px;">
                © {{ date('Y') }} Delivery AVAROA — Este es un correo automático, no respondas a este mensaje.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
