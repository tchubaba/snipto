import './bootstrap';
import Alpine from 'alpinejs';
import { sniptoComponent } from './snipto.js';

window.sniptoComponent = sniptoComponent;
window.Alpine = Alpine;

Alpine.start();
