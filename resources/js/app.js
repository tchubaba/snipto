import './bootstrap';
import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import { sniptoComponent } from './snipto.js';

window.sniptoComponent = sniptoComponent;
window.Alpine = Alpine;
window.QRCode = QRCode;

Alpine.start();
