document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('cyberpunk-bg');
    if (!canvas) {
        console.error("Canvas element #cyberpunk-bg not found.");
        document.body.style.backgroundColor = '#080510'; // Fallback
        return;
    }
    const ctx = canvas.getContext('2d');
    let width = canvas.width = window.innerWidth;
    let height = canvas.height = window.innerHeight;

    const characters = 'アァカサタナハマヤャラワガザダバパイィキシチニヒミリヰギジヂビピウゥクスツヌフムユュルグズブヅプエェケセテネヘメレヱゲゼデベペオォコソトノホモヨョロヲゴゾドボポヴッン0123456789:#*+%?-=[];{}()';
    const charArray = characters.split('');
    const fontSize = 14;
    let columns = Math.floor(width / fontSize);
    const drops = [];

    function initializeDrops() {
        columns = Math.floor(width / fontSize);
        drops.length = 0;
        for (let x = 0; x < columns; x++) {
            drops[x] = 1 + Math.random() * height;
        }
    }
    initializeDrops();

    const rootStyles = getComputedStyle(document.documentElement);
    const backgroundColor = (rootStyles.getPropertyValue('--canvas-bg-color') || 'rgba(0, 0, 0, 0.045)').trim();
    const charColors = [
        (rootStyles.getPropertyValue('--char-color-1') || '#00FF41').trim(),
        (rootStyles.getPropertyValue('--char-color-2') || '#00DD39').trim(),
        (rootStyles.getPropertyValue('--char-color-3') || '#00BB2F').trim(),
        (rootStyles.getPropertyValue('--char-color-4') || '#22FF55').trim(),
        (rootStyles.getPropertyValue('--char-color-5') || '#44FF77').trim()
    ];
    const highlightCharColor = (rootStyles.getPropertyValue('--highlight-char-color') || '#FFFFFF').trim();

    function draw() {
        ctx.fillStyle = backgroundColor;
        ctx.fillRect(0, 0, width, height);
        ctx.font = fontSize + 'px monospace';

        for (let i = 0; i < drops.length; i++) {
            const text = charArray[Math.floor(Math.random() * charArray.length)];
            const yPos = drops[i] * fontSize;

            if (drops[i] < 10 && Math.random() > 0.65) {
                ctx.fillStyle = highlightCharColor;
            } else {
                ctx.fillStyle = charColors[Math.floor(Math.random() * charColors.length)];
            }
            if (Math.random() > 0.992) {
                ctx.fillStyle = highlightCharColor;
            }

            ctx.fillText(text, i * fontSize, yPos);
            if (yPos > height && Math.random() > 0.988) {
                drops[i] = 0;
            }
            drops[i]++;
        }
    }

    // The rain advances one row per rendered frame, so capping the effective
    // frame rate slows the descent. ~40fps is a little slower than the default
    // (uncapped, ~60fps+). Lower TARGET_FPS to slow the rain down further.
    const TARGET_FPS = 40;
    const frameInterval = 1000 / TARGET_FPS;
    let animationFrameId;
    let lastFrameTime = 0;
    function animate(now) {
        animationFrameId = requestAnimationFrame(animate);
        if (now - lastFrameTime < frameInterval) {
            return;
        }
        lastFrameTime = now;
        draw();
    }

    let resizeTimeout;
    window.addEventListener('resize', () => {
        cancelAnimationFrame(animationFrameId);
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
            initializeDrops();
            requestAnimationFrame(animate);
        }, 250);
    });

    requestAnimationFrame(animate);
});
