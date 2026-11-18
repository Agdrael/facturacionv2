document.addEventListener('alpine:init', () => {
    window.Alpine.data('cashSignature', () => ({
        drawing: false,
        hasInk: false,
        error: '',
        init() { this.clear(); },
        clear() {
            const canvas = this.$refs.canvas;
            const context = canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.strokeStyle = '#1b1b1b';
            context.lineWidth = 2.5;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            this.hasInk = false;
            this.drawing = false;
            this.error = '';
        },
        point(event) {
            const canvas = this.$refs.canvas;
            const rect = canvas.getBoundingClientRect();
            return [(event.clientX - rect.left) * canvas.width / rect.width, (event.clientY - rect.top) * canvas.height / rect.height];
        },
        start(event) {
            event.preventDefault();
            this.$refs.canvas.setPointerCapture(event.pointerId);
            const [x, y] = this.point(event);
            const context = this.$refs.canvas.getContext('2d');
            context.beginPath();
            context.moveTo(x, y);
            this.drawing = true;
        },
        move(event) {
            if (!this.drawing) return;
            event.preventDefault();
            const [x, y] = this.point(event);
            const context = this.$refs.canvas.getContext('2d');
            context.lineTo(x, y);
            context.stroke();
            this.hasInk = true;
        },
        stop() { this.drawing = false; },
        prepare(event) {
            if (!this.hasInk) {
                event.preventDefault();
                this.error = 'Dibuja tu firma antes de continuar.';
                return;
            }
            this.$refs.signature.value = this.$refs.canvas.toDataURL('image/png');
        },
    }));
});
