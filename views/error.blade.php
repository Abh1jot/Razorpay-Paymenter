<body class="min-h-screen flex items-center justify-center">
    <div class="flex flex-col items-center gap-6 p-8">
        <div class="w-12 h-12">
            <svg viewBox="0 0 100 100" class="w-full h-full">
                <circle cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-width="3" class="text-red-500 animate-drawCircle" />
                <line x1="35" y1="35" x2="65" y2="65" stroke="currentColor" stroke-width="4" stroke-linecap="round" class="text-red-500 animate-drawXLeft" />
                <line x1="65" y1="35" x2="35" y2="65" stroke="currentColor" stroke-width="4" stroke-linecap="round" class="text-red-500 animate-drawXRight" />
            </svg>
        </div>
        <p class="text-red-500 text-center font-bold">
            {{ $error }}
        </p>
    </div>
</body>

<style>
    @keyframes drawCircle {
        to {
            stroke-dashoffset: 0;
        }
    }

    @keyframes drawX {
        to {
            stroke-dashoffset: 0;
        }
    }

    .animate-drawCircle {
        stroke-dasharray: 283;
        stroke-dashoffset: 283;
        animation: drawCircle 0.6s ease-out forwards;
    }

    .animate-drawXLeft,
    .animate-drawXRight {
        stroke-dasharray: 45;
        stroke-dashoffset: 45;
    }

    .animate-drawXLeft {
        animation: drawX 0.3s ease-out 0.6s forwards;
    }

    .animate-drawXRight {
        animation: drawX 0.3s ease-out 0.8s forwards;
    }
</style>