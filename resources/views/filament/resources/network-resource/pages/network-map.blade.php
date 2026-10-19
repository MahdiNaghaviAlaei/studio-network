<x-filament-panels::page>
    <div
        x-load
        x-load-src="{{ asset('network-editor/network-editor.js') }}?v={{ @filemtime(public_path('network-editor/network-editor.js')) }}"
        x-data="networkEditor(@js($this->initialGraph()))"
        wire:ignore
        wire:key="native-network-{{ $record->id }}"
        class="ne" :class="{ 'with-details': selected }" :data-theme="graph.settings.theme" dir="ltr"
        data-testid="network-editor"
    >
        <header class="ne-header">
            <div class="ne-brand">
                <span class="ne-brand-icon"><x-heroicon-o-cube-transparent /></span>
                <div><strong>NETWORK STUDIO</strong><small>نقشه تعاملی شبکه · {{ $record->name }}</small></div>
            </div>
            <div class="ne-header-end">
                <span class="ne-tag">FILAMENT 3</span>
                <span class="ne-status"><i :class="{dirty}"></i><span x-text="dirty ? 'تغییرات ذخیره‌نشده' : 'ذخیره شده'"></span></span>
                <button class="ne-btn" type="button" @click="reload()" title="باز کردن نسخه سرور"><x-heroicon-o-arrow-path /></button>
                <button class="ne-btn primary" type="button" @click="save()" :disabled="saving || readOnly" data-testid="save-map">
                    <x-heroicon-o-arrow-down-tray /><span x-text="saving ? 'در حال ذخیره…' : 'ذخیره نقشه'"></span>
                </button>
            </div>
        </header>
        <nav class="ne-toolbar">
            <button type="button" class="ne-btn" @click="addEquipment()" :disabled="readOnly" data-testid="add-device"><x-heroicon-o-plus /> افزودن تجهیز</button>
            <button type="button" class="ne-btn" @click="form('device')" :disabled="readOnly" data-testid="new-device"><x-heroicon-o-server /> تجهیز جدید</button>
            <button type="button" class="ne-btn" @click="form('zone')" :disabled="readOnly" data-testid="add-zone"><x-heroicon-o-square-3-stack-3d /> ناحیه</button>
            <button type="button" class="ne-btn" @click="form('text')" :disabled="readOnly"><x-heroicon-o-document-text /> متن</button>
            <button type="button" class="ne-btn" @click="form('rack')" :disabled="readOnly"><x-heroicon-o-server-stack /> رک</button>
            <span class="ne-separator"></span>
            <button type="button" class="ne-btn" :class="{active:connecting}" @click="toggleConnect()" :disabled="readOnly" data-testid="connect-mode"><x-heroicon-o-link /> اتصال</button>
            <button type="button" class="ne-btn icon" @click="undo()" :disabled="!history.length || readOnly" title="بازگشت"><x-heroicon-o-arrow-uturn-left /></button>
            <button type="button" class="ne-btn icon" @click="redo()" :disabled="!future.length || readOnly" title="انجام مجدد"><x-heroicon-o-arrow-uturn-right /></button>
            <span class="ne-separator"></span>
            <select aria-label="چیدمان خودکار" @change="layout($event.target.value); $event.target.value=''" :disabled="readOnly">
                <option value="">چیدمان خودکار</option><option value="TB">از بالا به پایین</option><option value="LR">از چپ به راست</option><option value="grid">شبکه‌ای</option>
            </select>
            <select aria-label="تم نقشه" :value="graph.settings.theme" @change="setTheme($event.target.value)">
                <option value="dark">تیره</option><option value="light">روشن</option><option value="neon">نئون</option><option value="matrix">ماتریکس</option>
            </select>
            <select aria-label="خروجی نقشه" @change="exportFile($event.target.value); $event.target.value=''" data-testid="export-format">
                <option value="">خروجی</option><option value="png">PNG</option><option value="svg">SVG</option><option value="json">JSON</option><option value="yaml">YAML</option><option value="markdown">Markdown</option>
            </select>
            <button type="button" class="ne-btn" @click="$refs.import.click()" :disabled="readOnly" data-testid="import-map"><x-heroicon-o-arrow-up-tray /> ورود فایل</button>
            <input x-ref="import" type="file" accept=".json,.yaml,.yml" hidden @change="importFile($event)" data-testid="import-input" />
            <button type="button" class="ne-btn icon" @click="openAction('history')" title="تاریخچه نسخه‌ها"><x-heroicon-o-clock /></button>
            <button type="button" class="ne-btn icon" @click="toggleReadOnly()" :class="{active: readOnly}" title="حالت فقط مشاهده"><x-heroicon-o-eye /></button>
            <button type="button" class="ne-btn icon" @click="fullscreen()" title="تمام صفحه"><x-heroicon-o-arrows-pointing-out /></button>
            <button type="button" class="ne-btn icon" @click="showHelp = !showHelp" title="راهنما"><x-heroicon-o-question-mark-circle /></button>
            <button type="button" class="ne-btn icon danger" @click="clear()" :disabled="readOnly" title="پاک کردن بوم"><x-heroicon-o-trash /></button>
        </nav>
        <div class="ne-notice error" x-show="error" x-cloak><span x-text="error" data-testid="editor-error"></span><button type="button" @click="error=''">×</button></div>
        <div class="ne-notice" x-show="message" x-cloak><span x-text="message" data-testid="editor-message"></span><button type="button" @click="message=''">×</button></div>
        <div class="ne-main">
            <aside class="ne-palette">
                <div class="ne-search"><input placeholder="جستجوی نام، IP یا برچسب…" x-model="search" @input="highlight()" aria-label="جستجوی تجهیزات" /></div>
                <div class="ne-section-title"><span>ELEMENTS</span><b x-text="items.length"></b></div>
                <div class="ne-palette-scroll ne-scroll">
                    <template x-if="!items.length && !search">
                        <div dir="rtl">
                            @foreach (['firewall' => 'فایروال', 'switch' => 'سوییچ', 'router' => 'روتر', 'server' => 'سرور', 'computer' => 'رایانه', 'proxmox' => 'مجازی‌سازی', 'nas' => 'ذخیره‌ساز', 'ap' => 'اکسس پوینت', 'siem' => 'SIEM', 'edr' => 'EDR', 'soar' => 'SOAR'] as $type => $label)
                                <button type="button" class="ne-device-button" @click="form('device', null, '{{ $type }}')" :disabled="readOnly"><x-heroicon-o-cube /><span>{{ $label }}</span><small>+</small></button>
                            @endforeach
                        </div>
                    </template>
                    <template x-for="item in filteredItems" :key="item.id">
                        <button type="button" class="ne-list-item" @click="focus(item.id)" @dblclick="form(item.kind,item)" :data-node-id="item.id">
                            <span class="ne-dot" :style="{background: item.kind === 'device' ? statusColor(item.data.status) : item.style.color}"></span>
                            <span><span x-text="item.label"></span><small x-text="item.kind==='device' ? (item.data.ip || item.type) : ({zone:'ناحیه',text:'یادداشت',rack:'رک'}[item.kind])"></small></span>
                        </button>
                    </template>
                </div>
                <div class="ne-palette-stats">
                    <div class="ne-stat-line"><span>DEVICES</span><b x-text="stats.total" data-testid="device-count"></b></div>
                    <template x-for="status in ['online','offline','warning','unknown']" :key="status">
                        <div class="ne-stat-line"><span class="ne-stat-label"><i class="ne-dot" :style="{background:statusColor(status)}"></i><span x-text="statusLabel(status)"></span></span><b x-text="stats[status]"></b></div>
                    </template>
                    <div class="ne-stat-line"><span>LINKS</span><b x-text="edgeItems.length" data-testid="edge-count"></b></div>
                    <p class="ne-footnote">وضعیت‌ها ثبت دستی هستند.<br>برای ویرایش، روی جزء دوبار کلیک کنید.</p>
                </div>
            </aside>
            <main class="ne-stage" :class="{grid:graph.settings.grid}">
                <div x-ref="canvas" class="ne-canvas" wire:ignore data-testid="graph-canvas"></div>
                <span class="ne-watermark">NETWORK TOPOLOGY</span>
                <div class="ne-empty" x-show="!items.length" x-cloak>
                    <span class="ne-empty-icon"><x-heroicon-o-share /></span>
                    <h3>نقشه شبکه را از اینجا بسازید</h3>
                    <p>تجهیزات را اضافه کنید، روی بوم بچینید و ارتباط آن‌ها را رسم کنید. این نقشه متعلق به همین شبکه است.</p>
                    <button class="ne-btn primary" type="button" @click="addEquipment()" :disabled="readOnly"><x-heroicon-o-plus /> انتخاب از تجهیزات موجود</button>
                </div>
                <div class="ne-view-tools">
                    <button type="button" @click="zoomBy(1.2)" title="بزرگ‌نمایی">+</button>
                    <button type="button" @click="zoomBy(1/1.2)" title="کوچک‌نمایی">−</button>
                    <button type="button" @click="fit()" title="نمایش کل نقشه"><x-heroicon-o-viewfinder-circle /></button>
                    <span class="ne-zoom" x-text="zoomValue + '%'"></span>
                </div>
                <button type="button" class="ne-minimap" x-show="minimap && graph.settings.minimap" @click="fit()" title="نمای کلی"><img :src="minimap" alt="نمای کلی نقشه" /></button>
            </main>
            <aside class="ne-details" x-show="selected" x-cloak>
                <template x-if="selected">
                    <div>
                        <div class="ne-details-header"><div><div class="ne-details-label" x-text="selected.source ? 'CONNECTION' : selected.type.toUpperCase()"></div><h3 x-text="selected.label || 'ارتباط'"></h3></div><button type="button" class="ne-btn icon" @click="selected=null">×</button></div>
                        <template x-if="!selected.source && selected.kind === 'device'">
                            <div>
                                <div class="ne-detail-row"><span>وضعیت</span><b :style="{color:statusColor(selected.data.status)}" x-text="statusLabel(selected.data.status)"></b></div>
                                <template x-if="items.some(n => n.id === selected.parent && n.kind === 'rack')">
                                    <div>
                                        <div class="ne-detail-row"><span>یونیت شروع در رک</span><b x-text="'U' + (selected.data.rack_u || 1)"></b></div>
                                        <div class="ne-detail-row"><span>ارتفاع تجهیز</span><b x-text="(selected.data.height_u || 1) + 'U'"></b></div>
                                    </div>
                                </template>
                                <template x-for="entry in [['IP',selected.data.ip],['Hostname',selected.data.hostname],['MAC',selected.data.mac],['سیستم‌عامل',selected.data.os],['CPU',selected.data.cpu],['RAM',selected.data.ram],['Disk',selected.data.disk]]" :key="entry[0]">
                                    <div class="ne-detail-row" x-show="entry[1]"><span x-text="entry[0]"></span><b x-text="entry[1]"></b></div>
                                </template>
                                <p class="ne-note" x-text="selected.data.notes"></p>
                                <template x-for="(service,index) in selected.data.services || []" :key="index"><div class="ne-service"><span x-text="service.name"></span><code x-text="(service.port || '—') + '/' + (service.protocol || 'tcp')"></code></div></template>
                                <template x-for="(property,index) in selected.data.properties || []" :key="index"><div class="ne-detail-row"><span x-text="property.key"></span><b x-text="property.value"></b></div></template>
                            </div>
                        </template>
                        <template x-if="selected.source">
                            <div><template x-for="entry in [['نوع',selected.type],['پورت مبدا',selected.data.source_port],['پورت مقصد',selected.data.target_port],['VLAN',selected.data.vlan],['سرعت',selected.data.speed]]" :key="entry[0]"><div class="ne-detail-row" x-show="entry[1]"><span x-text="entry[0]"></span><b x-text="entry[1]"></b></div></template></div>
                        </template>
                        <div class="ne-detail-actions">
                            <button type="button" class="ne-btn primary" @click="editSelected()" :disabled="readOnly" data-testid="edit-selected"><x-heroicon-o-pencil-square /> ویرایش</button>
                            <button type="button" class="ne-btn" @click="duplicate()" :disabled="readOnly || selected.source"><x-heroicon-o-document-duplicate /> کپی</button>
                            <button type="button" class="ne-btn" @click="collapse()" x-show="!selected.source && ['zone','rack','proxmox','docker_host'].includes(selected.type)" :disabled="readOnly">جمع / باز کردن</button>
                            <button type="button" class="ne-btn danger" @click="removeSelected()" :disabled="readOnly" data-testid="delete-selected"><x-heroicon-o-trash /> حذف</button>
                        </div>
                        <div class="ne-detail-actions"><button type="button" class="ne-btn" @click="align('y')" :disabled="readOnly">تراز افقی</button><button type="button" class="ne-btn" @click="align('x')" :disabled="readOnly">تراز عمودی</button></div>
                    </div>
                </template>
            </aside>
        </div>
        <footer class="ne-footer">
            <span><kbd>Ctrl S</kbd> Save · <kbd>Ctrl Z</kbd> Undo · <kbd>Del</kbd> Delete · <kbd>Shift</kbd> Multi-select</span>
            <span class="ne-auto"><label><input type="checkbox" x-model="autoSave" /> ذخیره خودکار</label><label><input type="checkbox" x-model="graph.settings.snap" @change="settingsChanged()" /> چسبیدن به شبکه</label><label><input type="checkbox" x-model="graph.settings.grid" @change="settingsChanged()" /> شبکه</label></span>
        </footer>
        <div class="ne-help" x-show="showHelp" x-cloak @click.self="showHelp=false">
            <div class="ne-help-card"><h3>راهنمای نقشه</h3><p>افزودن و ویرایش تجهیزات با فرم‌های فیلامنت انجام می‌شود. برای ساخت ارتباط، «اتصال» را فعال کنید و دو تجهیز را انتخاب کنید.</p><p>ذخیره نقشه <kbd>Ctrl + S</kbd></p><p>بازگشت / انجام مجدد <kbd>Ctrl + Z / Y</kbd></p><p>کپی / چسباندن <kbd>Ctrl + C / V</kbd></p><p>تکثیر تجهیز <kbd>Ctrl + D</kbd></p><p>حذف <kbd>Delete</kbd></p><p>وضعیت تجهیزات در این نسخه دستی است. هیچ ارتباطی با سرویس بیرونی برقرار نمی‌شود.</p><button type="button" class="ne-btn primary" @click="showHelp=false">متوجه شدم</button></div>
        </div>
    </div>
</x-filament-panels::page>
