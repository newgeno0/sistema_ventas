document.addEventListener('DOMContentLoaded', () => {
    let timeoutBusqueda;
    let carrito = [];
    
    // Referencias a los elementos del formulario
    const campoProducto = document.getElementById('producto');
    const campoPrecio = document.getElementById('precio');
    const campoCantidad = document.getElementById('cantidad');
    const campoCategoria = document.getElementById('categoria');
    const botonAgregar = document.querySelector('[onclick="agregarProducto()"]');
    const formVenta = document.getElementById('formVenta');

    // Evento para autocompletado con debounce
    campoProducto.addEventListener('input', function(e) {
        clearTimeout(timeoutBusqueda);
        timeoutBusqueda = setTimeout(() => {
            if (this.value.length >= 2) {
                buscarProductos(this.value);
            } else {
                document.getElementById('sugerencias').innerHTML = '';
                document.getElementById('sugerencias').style.display = 'none';
            }
        }, 300);
    });

    // Evento para clic en sugerencias
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('sugerencia')) {
            seleccionarSugerencia(e.target);
            campoPrecio.focus();
        }
    });

    // Configuración completa de navegación por teclado
    campoProducto.addEventListener('keydown', function(e) {
        // Manejo de flechas para sugerencias
        if (e.key === 'ArrowDown' && document.querySelector('.sugerencia')) {
            const sugerencias = document.querySelectorAll('.sugerencia');
            const indiceSeleccionado = Array.from(sugerencias).findIndex(s => s.classList.contains('seleccionado'));
            
            if (indiceSeleccionado >= 0) {
                sugerencias[indiceSeleccionado].classList.remove('seleccionado', 'bg-primary', 'text-white');
            }
            
            const nuevoIndice = indiceSeleccionado < sugerencias.length - 1 ? indiceSeleccionado + 1 : 0;
            sugerencias[nuevoIndice].classList.add('seleccionado', 'bg-primary', 'text-white');
            sugerencias[nuevoIndice].scrollIntoView({ block: 'nearest' });
            
            e.preventDefault();
        } 
        else if (e.key === 'ArrowUp' && document.querySelector('.sugerencia')) {
            const sugerencias = document.querySelectorAll('.sugerencia');
            const indiceSeleccionado = Array.from(sugerencias).findIndex(s => s.classList.contains('seleccionado'));
            
            if (indiceSeleccionado >= 0) {
                sugerencias[indiceSeleccionado].classList.remove('seleccionado', 'bg-primary', 'text-white');
            }
            
            const nuevoIndice = indiceSeleccionado <= 0 ? sugerencias.length - 1 : indiceSeleccionado - 1;
            sugerencias[nuevoIndice].classList.add('seleccionado', 'bg-primary', 'text-white');
            sugerencias[nuevoIndice].scrollIntoView({ block: 'nearest' });
            
            e.preventDefault();
        }
        else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            
            // Seleccionar sugerencia si está activa
            const sugerenciaSeleccionada = document.querySelector('.sugerencia.seleccionado');
            if (sugerenciaSeleccionada) {
                seleccionarSugerencia(sugerenciaSeleccionada);
            }
            
            // Auto-detectar categoría para impresiones/copias
            if (campoProducto.value.toLowerCase().includes('impres') || 
                campoProducto.value.toLowerCase().includes('copia')) {
                campoCategoria.value = 'impresion';
            }
            
            campoPrecio.focus();
        }
    });

    campoPrecio.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            campoCantidad.focus();
        }
    });

    campoCantidad.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            campoCategoria.focus();
            
            // Mostrar opciones del select
            campoCategoria.size = campoCategoria.options.length;
            campoCategoria.addEventListener('blur', function() {
                this.size = 1;
            }, {once: true});
        }
    });

    campoCategoria.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            botonAgregar.focus();
        }
    });

    botonAgregar.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (agregarProducto()) {
                campoProducto.focus();
            }
        }
    });

    // Delegación de eventos para acciones del carrito
    document.getElementById('lista-productos').addEventListener('click', function(e) {
        const target = e.target.closest('button');
        if (!target) return;

        const fila = target.closest('tr');
        const index = parseInt(fila.dataset.index);
        
        if (target.classList.contains('btn-eliminar')) {
            eliminarProducto(index);
        } else if (target.classList.contains('btn-editar')) {
            editarProducto(index);
        }
    });

    // Función para buscar productos
    const buscarProductos = async (query) => {
        try {
            const response = await fetch('includes/autocompletar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `query=${encodeURIComponent(query)}`
            });
            
            const data = await response.text();
            const divSugerencias = document.getElementById('sugerencias');
            divSugerencias.innerHTML = data;
            divSugerencias.style.display = 'block';
        } catch (error) {
            mostrarError('Error al buscar productos');
        }
    }

    // Función para seleccionar sugerencia
    const seleccionarSugerencia = (elemento) => {
        const texto = elemento.textContent.split(' - $');
        campoProducto.value = texto[0].trim();
        campoPrecio.value = texto[1].trim();
        document.getElementById('sugerencias').style.display = 'none';
    }

    // Función para agregar producto (global)
    window.agregarProducto = () => {
        const producto = campoProducto.value.trim();
        const precio = parseFloat(campoPrecio.value);
        const cantidad = parseInt(campoCantidad.value);
        const categoria = campoCategoria.value;

        // Validación reforzada
        if (!producto || isNaN(precio) || precio <= 0 || isNaN(cantidad) || cantidad <= 0) {
            mostrarError('Complete todos los campos correctamente');
            return false;
        }

        // Agregar al carrito
        carrito.push({
            producto,
            precio,
            cantidad,
            categoria,
            subtotal: (precio * cantidad).toFixed(2)
        });

        actualizarListaProductos();
        calcularTotal();
        limpiarFormulario();
        
        // Mantener el foco en el campo de producto
        campoProducto.focus();
        return true;
    }

    // Función para actualizar lista de productos
    const actualizarListaProductos = () => {
        const tbody = document.getElementById('lista-productos');
        tbody.innerHTML = carrito.map((item, index) => `
            <tr data-index="${index}">
                <td>${escapeHTML(item.producto)}</td>
                <td>$${item.precio.toFixed(2)}</td>
                <td>${item.cantidad}</td>
                <td>$${item.subtotal}</td>
                <td>${item.categoria}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-warning btn-editar">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-danger btn-eliminar">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    }

    // Función para editar producto
    const editarProducto = (index) => {
        const producto = carrito[index];
        
        campoProducto.value = producto.producto;
        campoPrecio.value = producto.precio;
        campoCantidad.value = producto.cantidad;
        campoCategoria.value = producto.categoria;

        carrito.splice(index, 1);
        actualizarListaProductos();
        calcularTotal();
        campoProducto.focus();
    }

    // Función para eliminar producto
    const eliminarProducto = (index) => {
        if (confirm('¿Estás seguro de eliminar este producto?')) {
            carrito.splice(index, 1);
            actualizarListaProductos();
            calcularTotal();
        }
    }

    // Función para calcular total
    const calcularTotal = () => {
        const total = carrito.reduce((sum, item) => sum + parseFloat(item.subtotal), 0);
        document.getElementById('total').textContent = total.toFixed(2);
    }

    // Función para limpiar formulario
    const limpiarFormulario = () => {
        campoProducto.value = '';
        campoPrecio.value = '';
        campoCantidad.value = '1';
        campoCategoria.value = 'producto';
        document.getElementById('sugerencias').style.display = 'none';
    }

    // Función para guardar nota
    window.guardarNota = async () => {
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        
        if (carrito.length === 0) {
            mostrarError('Agrega al menos un producto');
            return;
        }

        const productosParaEnviar = carrito.map(item => ({
            nombre: item.producto,
            precio: item.precio,
            cantidad: item.cantidad,
            categoria: item.categoria
        }));

        try {
            const response = await fetch('ventas/agregar_venta.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `productos=${encodeURIComponent(JSON.stringify(productosParaEnviar))}&total=${document.getElementById('total').textContent}&csrf_token=${csrfToken}`
            });

            const resultado = await response.json();
            
            if (response.ok && resultado.success) {
                carrito = [];
                actualizarListaProductos();
                calcularTotal();
                mostrarMensaje(resultado.message || 'Venta guardada exitosamente');
                
                if (typeof actualizarResumenDiario === 'function') {
                    actualizarResumenDiario();
                }
            } else {
                throw new Error(resultado.error || 'Error al guardar la venta');
            }
        } catch (error) {
            mostrarError(error.message);
            console.error('Error:', error);
        }
    }

    // Función para vaciar carrito
    window.vaciarCarrito = () => {
        if (carrito.length === 0) return;
        
        if (confirm('¿Estás seguro de vaciar el carrito?')) {
            carrito = [];
            actualizarListaProductos();
            calcularTotal();
            mostrarMensaje('Carrito vaciado');
        }
    }

    // Helper para escape de HTML
    const escapeHTML = (str) => {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Mostrar mensajes de error
    const mostrarError = (mensaje) => {
        const alertas = document.getElementById('alertas') || crearContenedorAlertas();
        const alerta = document.createElement('div');
        
        alerta.className = 'alert alert-danger alert-dismissible fade show';
        alerta.innerHTML = `
            ${mensaje}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        alertas.prepend(alerta);
        setTimeout(() => alerta.remove(), 5000);
    }

    // Mostrar mensajes de éxito
    const mostrarMensaje = (mensaje) => {
        const alertas = document.getElementById('alertas') || crearContenedorAlertas();
        const alerta = document.createElement('div');
        
        alerta.className = 'alert alert-success alert-dismissible fade show';
        alerta.innerHTML = `
            ${mensaje}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        alertas.prepend(alerta);
        setTimeout(() => alerta.remove(), 3000);
    }

    const crearContenedorAlertas = () => {
        const container = document.querySelector('.container');
        const alertas = document.createElement('div');
        alertas.id = 'alertas';
        alertas.className = 'position-fixed top-0 end-0 p-3';
        alertas.style.zIndex = '1100';
        container.prepend(alertas);
        return alertas;
    }
});
