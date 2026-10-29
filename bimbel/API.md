# REST API Bimbel (untuk aplikasi Flutter)

Alamat dasar: `http://localhost/bimbel/api/` — bila mod_rewrite mati: `http://localhost/bimbel/api/index.php?r=<endpoint>`.
Request JSON (`Content-Type: application/json`). Respons:
`{"success": true, "data": ...}` atau `{"success": false, "error": "...", "errors": [...]}`.
Setelah login, kirim header `Authorization: Bearer <token>` (token berlaku 30 hari). API hanya untuk **siswa** dan **pengajar**.

## Umum
| Metode | Endpoint | Keterangan |
|---|---|---|
| POST | `auth/login` | `{username, password, perangkat?}` -> `{token, berlaku_hari, user}` |
| POST | `auth/register` | Registrasi siswa: `{username, password, nama_lengkap, jenis_kelamin, tanggal_lahir?, tingkat, sekolah_asal?, no_telp?, email?, nama_wali?, no_telp_wali?, alamat?, kelas: [id_kelas...]}`; status awal "menunggu" verifikasi admin |
| GET | `kelas` | Daftar kelas yang dibuka (publik, untuk form registrasi) |
| POST | `auth/logout` | Hapus token |
| GET | `me` | Profil user login |
| POST | `auth/password` | Ganti password |
| GET | `pengumuman?page=&per=` | Pengumuman & info perubahan jadwal untuk user |

## Siswa
| Metode | Endpoint | Keterangan |
|---|---|---|
| GET | `siswa/jadwal` | Jadwal belajar |
| GET | `siswa/mapel` | Daftar mata pelajaran/kelas + sisa kuota |
| POST | `siswa/kelas/{id}/daftar` | Ajukan masuk kelas (menunggu persetujuan admin) |
| GET | `siswa/pengajar` | Profil pengajar |
| GET | `siswa/absensi` | Riwayat & rekap kehadiran |
| GET | `siswa/pembayaran` | Tagihan & status pembayaran |
| POST | `siswa/pembayaran/{id}/lapor` | `{metode: tunai|transfer|qris}` — lapor sudah bayar |

## Pengajar
| Metode | Endpoint | Keterangan |
|---|---|---|
| GET | `pengajar/jadwal` | Jadwal mengajar |
| GET | `pengajar/kelas` | Kelas yang diampu |
| GET | `pengajar/kelas/{id}/siswa` | Daftar nama siswa per kelas |
| GET | `pengajar/pertemuan` | Riwayat pertemuan |
| GET | `pengajar/absensi?jadwal=&tanggal=` | Data form absensi (siswa + nilai tersimpan) |
| POST | `pengajar/absensi` | `{id_jadwal, tanggal, pertemuan_ke?, kehadiran_pengajar?, materi?, catatan?, siswa:[{id_siswa,status,keterangan}]}` |

Contoh: `curl -X POST http://localhost/bimbel/api/auth/login -H "Content-Type: application/json" -d '{"username":"siswa1","password":"bimbel123"}'`
