<?php
session_start();
require_once 'config/database.php';
require_once 'lib/core.php';

// Cek apakah user sudah memiliki sesi (sudah login)
// Jika sudah, lempar ke file index.php (Router) agar diarahkan sesuai jabatannya
if (isset($_SESSION['role'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        $user = absensi_auth($pdo, $username, $password);

        if ($user) {
            // Set Sesi Login
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Redirect (Arahkan) sesuai Role/Jabatan
            if ($user['role'] === 'admin') {
                header("Location: admin/index.php");
            } elseif ($user['role'] === 'guru') {
                header("Location: guru/index.php");
            } else {
                header("Location: student/index.php");
            }
            exit;
        } else {
            $error = 'Username atau password yang Anda masukkan salah!';
        }
    } else {
        $error = 'Perhatian: Username dan password wajib diisi!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Absensi SMAN 1 Bangunrejo</title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* CSS Reset & Font */
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        
        /* Background Premium (Foto Gedung + Overlay Gelap) */
        body { 
            background: linear-gradient(rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.85)), url('assets/sman1bare.jpeg') center/cover no-repeat fixed;
            background-color: #0f172a;
            display: flex; justify-content: center; align-items: center; 
            min-height: 100vh; padding: 20px; 
        }
        
        /* Kotak Login */
        .login-container { 
            background: rgba(255, 255, 255, 0.98); 
            padding: 40px; 
            border-radius: 24px; 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); 
            width: 100%; max-width: 420px; 
            transition: transform 0.3s ease; 
        }
        .login-container:hover { transform: translateY(-5px); }
        
        /* Header Logo & Judul */
        .brand-header { text-align: center; margin-bottom: 35px; }
        .icon-logo { width: 90px; height: 90px; object-fit: contain; margin-bottom: 15px; border-radius: 18px; background: white; padding: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .brand-header h1 { color: #0f172a; font-size: 24px; font-weight: 800; margin-bottom: 8px; letter-spacing: -0.5px; }
        .brand-header p { color: #64748b; font-size: 14px; font-weight: 500; }
        
        /* Input Grup dengan Ikon di dalam */
        .form-group { margin-bottom: 22px; }
        .form-group label { display: block; margin-bottom: 8px; color: #475569; font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper i { position: absolute; left: 16px; color: #94a3b8; font-size: 16px; transition: color 0.3s; }
        
        .input-wrapper input { 
            width: 100%; padding: 14px 16px 14px 45px; 
            border: 2px solid #e2e8f0; border-radius: 12px; 
            font-size: 15px; color: #1e293b; background: #f8fafc; 
            transition: all 0.3s ease; outline: none; 
        }
        .input-wrapper input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); }
        .input-wrapper input:focus + i, .input-wrapper input:not(:placeholder-shown) + i { color: #3b82f6; }
        
        /* Tombol Login */
        .btn-submit { 
            width: 100%; padding: 15px; background: #3b82f6; color: white; 
            border: none; border-radius: 12px; font-size: 16px; font-weight: 700; 
            cursor: pointer; transition: all 0.3s ease; margin-top: 10px; 
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn-submit:hover { background: #2563eb; transform: translateY(-2px); box-shadow: 0 8px 15px rgba(59, 130, 246, 0.3); }
        
        /* Pesan Error */
        .alert { 
            background: #fee2e2; color: #991b1b; padding: 14px 16px; 
            border-radius: 10px; font-size: 14px; margin-bottom: 25px; 
            font-weight: 600; display: flex; align-items: center; gap: 10px; 
            border-left: 4px solid #ef4444; 
        }

        /* Teks Info di Bawah */
        .login-footer { margin-top: 30px; text-align: center; color: #64748b; font-size: 13px; }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="brand-header">
            <img src="assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo" class="icon-logo">
            <h1>Absensi SMAN 1 Bangunrejo</h1>
            <p>Silakan masuk menggunakan akun Anda</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username / NISN</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" placeholder="Masukkan Username / NISN" required autocomplete="off">
                    <i class="fa-solid fa-user"></i>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" placeholder="Masukkan Password Anda" required>
                    <i class="fa-solid fa-lock"></i>
                </div>
            </div>
            
            <button type="submit" class="btn-submit">
                Masuk Ke Sistem <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>
        
        <div class="login-footer">
            Absensi SMAN 1 Bangunrejo &copy; <?= date('Y') ?>
        </div>
    </div>

</body>
</html>