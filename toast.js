// Sistema de Notificações Toast (estilo iPhone)
const Toast = {
    container: null,
    activeToasts: [],
    maxToasts: 3,
    
    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            `;
            document.body.appendChild(this.container);
        }
    },
    
    show(message, type = 'success', duration = 3000) {
        this.init();
        
        // Remove toasts antigos se exceder o máximo
        while (this.activeToasts.length >= this.maxToasts) {
            const oldToast = this.activeToasts.shift();
            this.remove(oldToast.element);
        }
        
        const toast = document.createElement('div');
        
        // Cores baseadas no tipo
        const colors = {
            success: { bg: '#34C759', icon: '✓' },
            error: { bg: '#FF3B30', icon: '✕' },
            warning: { bg: '#FF9500', icon: '!' },
            info: { bg: '#007AFF', icon: 'i' }
        };
        
        const color = colors[type] || colors.success;
        
        toast.style.cssText = `
            background: ${color.bg};
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 15px;
            font-weight: 500;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 280px;
            max-width: 400px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            animation: toastSlideIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            pointer-events: auto;
        `;
        
        toast.innerHTML = `
            <span style="
                width: 22px;
                height: 22px;
                background: rgba(255,255,255,0.3);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: bold;
            ">${color.icon}</span>
            <span>${message}</span>
        `;
        
        this.container.appendChild(toast);
        
        const toastObj = { element: toast, timeout: null };
        this.activeToasts.push(toastObj);
        
        // Auto-remove após a duração
        toastObj.timeout = setTimeout(() => {
            this.remove(toast);
        }, duration);
        
        // Permite clicar para fechar
        toast.addEventListener('click', () => {
            clearTimeout(toastObj.timeout);
            this.remove(toast);
        });
        
        return toast;
    },
    
    remove(element) {
        if (!element || element.classList.contains('removing')) return;
        
        element.classList.add('removing');
        element.style.animation = 'toastSlideOut 0.2s ease forwards';
        
        setTimeout(() => {
            if (element.parentNode) {
                element.parentNode.removeChild(element);
            }
            
            // Remove da lista de toasts ativos
            const index = this.activeToasts.findIndex(t => t.element === element);
            if (index > -1) {
                this.activeToasts.splice(index, 1);
            }
        }, 200);
    },
    
    success(message, duration) {
        return this.show(message, 'success', duration);
    },
    
    error(message, duration) {
        return this.show(message, 'error', duration);
    },
    
    warning(message, duration) {
        return this.show(message, 'warning', duration);
    },
    
    info(message, duration) {
        return this.show(message, 'info', duration);
    }
};

// Adiciona estilos CSS para animações
const toastStyles = document.createElement('style');
toastStyles.textContent = `
    @keyframes toastSlideIn {
        from {
            opacity: 0;
            transform: translateY(-20px) scale(0.9);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    
    @keyframes toastSlideOut {
        from {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        to {
            opacity: 0;
            transform: translateY(-10px) scale(0.9);
        }
    }
`;
document.head.appendChild(toastStyles);
