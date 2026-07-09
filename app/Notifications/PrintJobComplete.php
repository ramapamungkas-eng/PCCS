<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Notifikasi yang dikirim setelah job cetak selesai (sukses atau gagal).
 * Disimpan langsung ke database agar status segera terlihat oleh UI polling.
 */
class PrintJobComplete extends Notification
{
    /**
     * URL publik untuk mengunduh file PDF yang dihasilkan.
     * Akan bernilai null jika terjadi kegagalan.
     */
    public ?string $downloadUrl;

    /**
     * Pesan status yang akan ditampilkan kepada pengguna.
     * (Contoh: "File Anda siap" atau "Terjadi error").
     */
    public string $message;

    /**
     * Buat instance notifikasi baru.
     *
     * @param  ?string  $downloadUrl  URL untuk mengunduh file, atau null jika gagal.
     * @param  string  $message  Pesan status untuk pengguna.
     */
    public function __construct(?string $downloadUrl = null, string $message = 'File PDF Anda telah siap untuk diunduh.')
    {
        $this->downloadUrl = $downloadUrl;
        $this->message = $message;
    }

    /**
     * Tentukan channel pengiriman notifikasi.
     */
    public function via(object $notifiable): array
    {
        // 'database' akan menyimpan notifikasi di tabel 'notifications'
        return ['database'];
    }

    /**
     * Mendapatkan representasi array dari notifikasi.
     * Data ini yang akan disimpan di kolom 'data' (JSON) pada tabel 'notifications'.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Pekerjaan Cetak Selesai',
            'message' => $this->message,
            'download_url' => $this->downloadUrl, // Akan diambil oleh print-notifier.blade.php
            'timestamp' => now()->toDateTimeString(),
        ];
    }
}
