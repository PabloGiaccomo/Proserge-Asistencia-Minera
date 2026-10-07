export class SignaturePadController {
    constructor(canvas, options = {}) {
        this.canvas = canvas;
        this.context = canvas.getContext('2d');
        this.ratio = options.ratio || (() => globalThis.devicePixelRatio || 1);
        this.minImageSize = options.minImageSize || 0;
        this.strokes = [];
        this.pointerId = null;
        this.imported = null;
        this.imageRevision = 0;
        canvas.style.touchAction = 'none';
        canvas.addEventListener('pointerdown', event => this.start(event));
        canvas.addEventListener('pointermove', event => this.move(event));
        canvas.addEventListener('pointerup', event => this.stop(event));
        canvas.addEventListener('pointercancel', event => this.stop(event));
        canvas.addEventListener('lostpointercapture', event => this.stop(event));
        this.resize();
    }

    get pointCount() { return this.strokes.reduce((sum, stroke) => sum + stroke.length, 0); }

    point(event) {
        const rect = this.canvas.getBoundingClientRect();
        return { x: Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)), y: Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height)) };
    }

    start(event) {
        if (this.pointerId !== null || (event.button !== undefined && event.button !== 0)) return;
        event.preventDefault();
        this.resize();
        this.pointerId = event.pointerId;
        this.strokes.push([this.point(event)]);
        try { this.canvas.setPointerCapture(event.pointerId); } catch { /* Pointer may have ended. */ }
    }

    move(event) {
        if (this.pointerId !== event.pointerId) return;
        event.preventDefault();
        const stroke = this.strokes.at(-1);
        const point = this.point(event);
        const last = stroke.at(-1);
        if (last.x === point.x && last.y === point.y) return;
        stroke.push(point);
        this.drawSegment(last, point);
    }

    stop(event) {
        if (this.pointerId !== event.pointerId) return;
        event.preventDefault();
        const id = this.pointerId;
        this.pointerId = null;
        try { this.canvas.releasePointerCapture(id); } catch { /* Capture already released. */ }
        this.changed();
    }

    drawSegment(from, to) {
        const ctx = this.context;
        ctx.beginPath();
        ctx.moveTo(from.x * this.width, from.y * this.height);
        ctx.lineTo(to.x * this.width, to.y * this.height);
        ctx.stroke();
    }

    redraw() {
        this.context.clearRect(0, 0, this.width, this.height);
        if (this.imported) this.context.drawImage(this.imported, 0, 0, this.width, this.height);
        this.strokes.forEach(stroke => stroke.slice(1).forEach((point, index) => this.drawSegment(stroke[index], point)));
    }

    resize() {
        const rect = this.canvas.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        const ratio = Math.min(2400 / Math.max(rect.width, rect.height), Math.max(1, this.ratio(), this.minImageSize / Math.min(rect.width, rect.height)));
        const width = Math.round(rect.width * ratio), height = Math.round(rect.height * ratio);
        if (this.canvas.width === width && this.canvas.height === height && this.width === rect.width && this.height === rect.height) return;
        this.width = rect.width; this.height = rect.height;
        this.canvas.width = width; this.canvas.height = height;
        this.context.setTransform(width / rect.width, 0, 0, height / rect.height, 0, 0);
        Object.assign(this.context, { lineWidth: 2.4, lineCap: 'round', lineJoin: 'round', strokeStyle: '#0f172a' });
        this.redraw();
        this.changed();
    }

    hasInk() { return Boolean(this.imported) || this.strokes.some(stroke => stroke.length > 1); }

    toPayload() {
        if (!this.hasInk()) throw new Error('Dibuja tu firma completa dentro del recuadro.');
        const points = this.strokes.filter(stroke => stroke.length > 1).flat();
        const bounds = points.reduce((result, point) => ({
            min_x: Math.min(result.min_x, point.x * this.canvas.width), min_y: Math.min(result.min_y, point.y * this.canvas.height),
            max_x: Math.max(result.max_x, point.x * this.canvas.width), max_y: Math.max(result.max_y, point.y * this.canvas.height),
        }), { min_x: this.canvas.width, min_y: this.canvas.height, max_x: 0, max_y: 0 });
        return { firma_data_url: this.canvas.toDataURL('image/png'), ink: { point_count: points.length, bounds } };
    }

    clear() {
        this.pointerId = null;
        this.strokes = [];
        this.imported = null;
        this.imageRevision++;
        this.redraw();
        this.changed();
    }

    importImage(dataUrl) {
        if (!dataUrl?.startsWith('data:image/png;base64,')) return;
        const revision = ++this.imageRevision;
        const image = new Image();
        image.onload = () => {
            if (revision !== this.imageRevision) return;
            this.imported = image;
            this.redraw();
            this.changed();
        };
        image.src = dataUrl;
    }

    changed() { this.canvas.dispatchEvent(new Event('signature:changed', { bubbles: true })); }
}

export function initSignaturePads(root = document) {
    return [...root.querySelectorAll('[data-signature-pad]')].map(canvas => {
        if (canvas.signaturePad) return canvas.signaturePad;
        const wrap = canvas.closest('.signature-pad-wrap');
        const input = wrap?.querySelector('[data-signature-input]');
        const error = wrap?.querySelector('[data-signature-error]');
        const pad = new SignaturePadController(canvas, { minImageSize: canvas.dataset.minInk ? 300 : 0 });
        canvas.signaturePad = pad;
        const showError = visible => {
            canvas.classList.toggle('is-invalid', visible);
            if (error) { error.hidden = !visible; error.style.display = visible ? 'block' : 'none'; }
        };
        canvas.addEventListener('signature:changed', () => {
            if (input) input.value = pad.hasInk() ? pad.toPayload().firma_data_url : '';
            if (pad.hasInk()) showError(false);
        });
        if (input?.value) pad.importImage(input.value);
        wrap?.querySelector('[data-clear-signature]')?.addEventListener('click', () => { pad.clear(); showError(false); });
        const form = canvas.closest('form');
        const validate = () => {
            const required = canvas.dataset.required === '1' || input?.dataset.required === '1';
            let valid = !required || pad.hasInk() || Boolean(input?.value);
            if (valid && canvas.dataset.minInk) {
                if (!pad.hasInk()) valid = false;
                else {
                    const { ink } = pad.toPayload();
                    valid = ink.point_count >= 10 && ink.bounds.max_x - ink.bounds.min_x >= 20 && ink.bounds.max_y - ink.bounds.min_y >= 10;
                }
            }
            showError(!valid);
            if (!valid) canvas.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return valid;
        };
        if (form) {
            form._validateSignature = validate;
            form.addEventListener('submit', event => { if (!validate()) event.preventDefault(); });
        }
        window.addEventListener('resize', () => pad.resize());
        window.addEventListener('orientationchange', () => requestAnimationFrame(() => pad.resize()));
        if (globalThis.ResizeObserver) new ResizeObserver(() => pad.resize()).observe(canvas);
        return pad;
    });
}
