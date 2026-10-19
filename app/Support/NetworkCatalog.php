<?php

namespace App\Support;

class NetworkCatalog
{
    public static function devices(): array
    {
        return [
            'isp' => 'اینترنت / ISP', 'router' => 'روتر', 'firewall' => 'فایروال', 'switch' => 'سوییچ',
            'server' => 'سرور', 'proxmox' => 'میزبان مجازی‌سازی', 'vm' => 'ماشین مجازی', 'lxc' => 'کانتینر LXC',
            'docker_host' => 'میزبان Docker', 'docker_container' => 'کانتینر Docker', 'nas' => 'ذخیره‌ساز NAS',
            'ap' => 'اکسس پوینت', 'computer' => 'رایانه', 'laptop' => 'لپ‌تاپ', 'mobile' => 'موبایل',
            'iot' => 'IoT', 'camera' => 'دوربین', 'printer' => 'چاپگر', 'ups' => 'UPS', 'pdu' => 'PDU',
            'patch_panel' => 'پچ پنل', 'siem' => 'SIEM', 'edr' => 'EDR', 'soar' => 'SOAR', 'generic' => 'تجهیز دیگر',
        ];
    }

    public static function statuses(): array
    {
        return ['unknown' => 'نامشخص', 'online' => 'آنلاین', 'offline' => 'آفلاین', 'warning' => 'نیازمند بررسی'];
    }

    public static function links(): array
    {
        return ['ethernet' => 'Ethernet', 'fibre' => 'فیبر نوری', 'wifi' => 'Wi-Fi', 'vlan' => 'VLAN', 'virtual' => 'مجازی', 'iot' => 'IoT', 'electrical' => 'برق'];
    }
}
