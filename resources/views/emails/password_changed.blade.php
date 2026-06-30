<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contraseña actualizada</title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f5; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f5; padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(1,94,128,0.12);">
          <tr>
            <td style="background-color:#015E80; padding:28px 32px; text-align:center;">
              <span style="color:#ffffff; font-size:20px; font-weight:700; letter-spacing:0.5px;">Delivery AVAROA</span>
            </td>
          </tr>

          <tr>
            <td style="padding:36px 32px; text-align:center;">
              <div style="width:64px; height:64px; border-radius:50%; background-color:#dcfce7; margin:0 auto 18px auto; line-height:64px; font-size:32px;">
                ✅
              </div>
              <p style="margin:0 0 4px 0; color:#0f172a; font-size:18px; font-weight:700;">
                Hola{{ isset($name) && $name ? ', ' . $name : '' }}
              </p>
              <p style="margin:10px 0 0 0; color:#475569; font-size:14.5px; line-height:1.6;">
                Tu contraseña fue actualizada correctamente.<br>
                Ya podés iniciar sesión con tu nueva contraseña.
              </p>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;">
                <tr>
                  <td style="background-color:#fef9c3; border-radius:10px; padding:14px 16px; text-align:left;">
                    <span style="color:#854d0e; font-size:13px; line-height:1.5;">
                      ⚠️ <strong>¿No fuiste vos?</strong> Si no realizaste este cambio, contactá a soporte de inmediato.
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

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
