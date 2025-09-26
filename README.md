# Sistema de Desempeño por Consultor

## 📋 Descripción
Sistema web para análisis de desempeño comercial por consultor, desarrollado con PHP 8+, Bootstrap 5, Chart.js y MySQL.

## 🎯 Funcionalidades
- **Relatório**: Análisis detallado de receitas, costos fijos, comisiones y lucro por mes
- **Gráfico**: Visualización comparativa de desempeño con barras y línea de costo medio
- **Pizza**: Distribución porcentual de receita líquida por consultor

## 🛠️ Stack Tecnológico
- **Backend**: PHP 8+ con PDO
- **Frontend**: Bootstrap 5.3.3 + Chart.js 4.4.1
- **Base de Datos**: MySQL/MariaDB
- **API**: REST con Fetch API

## 📊 Requisitos del Cliente
- ✅ Valores financieros en formato brasileño (R$ 10.000,00)
- ✅ Fechas en formato dd/mm/aaaa
- ✅ Consulta de consultores con JOIN entre CAO_USUARIO y PERMISSAO_SISTEMA
- ✅ Cálculos exactos según especificación matemática

## 🚀 Instalación

### Requisitos
- PHP 8.0+
- MySQL 5.7+
- Servidor web (Apache/Nginx)

### Configuración
1. Clona el repositorio
2. Configura la base de datos en `con_desempenho.php` (líneas 9-12)
3. Importa las tablas necesarias:
   - CAO_USUARIO
   - PERMISSAO_SISTEMA
   - CAO_FATURA
   - CAO_OS
   - CAO_SALARIO

### Variables de Entorno
Copia `config.env.example` a `.env` y configura:
```
DB_HOST=localhost
DB_NAME=performance_comercial
DB_USER=root
DB_PASS=
```

## 📈 Cálculos Implementados

### Receita Líquida
```
Receita Líquida = VALOR - (VALOR * TOTAL_IMP_INC)
```

### Comissão
```
Comissão = (VALOR - VALOR*TOTAL_IMP_INC) * COMISSAO_CN
```

### Lucro
```
Lucro = Receita Líquida - (Custo Fixo + Comissão)
```

## 🎨 Características de UI/UX
- Diseño responsive con Bootstrap 5
- Tema oscuro/claro intercambiable
- Gráficos interactivos con Chart.js
- Formato brasileño automático
- Exportación a CSV
- Validación de formularios

## 📝 Estructura del Proyecto
```
proyecto_prueba/
├── con_desempenho.php      # Archivo principal
├── config.env.example      # Configuración de ejemplo
├── css/
│   └── style.css          # Estilos adicionales
├── js/
│   ├── cor_fundo.js       # Funciones de color
│   ├── popcalendar.js     # Calendario
│   └── script_flash.js    # Scripts Flash
└── README.md              # Este archivo
```

## 🔧 API Endpoints
- `?route=consultores` - Lista de consultores activos
- `?route=relatorio` - Genera relatório detallado
- `?route=grafico` - Datos para gráfico de barras
- `?route=pizza` - Datos para gráfico de pizza

## 📊 Base de Datos

### Tablas Principales
- **CAO_USUARIO**: Información de consultores
- **PERMISSAO_SISTEMA**: Permisos y activación
- **CAO_FATURA**: Facturas y valores
- **CAO_OS**: Órdenes de servicio
- **CAO_SALARIO**: Salarios fijos

### Consulta de Consultores
```sql
SELECT U.CO_USUARIO, U.NO_USUARIO
FROM CAO_USUARIO U
JOIN PERMISSAO_SISTEMA P ON P.CO_USUARIO = U.CO_USUARIO
WHERE P.CO_SISTEMA = 1
  AND P.IN_ATIVO = 'S'
  AND P.CO_TIPO_USUARIO IN (0,1,2)
ORDER BY U.NO_USUARIO
```

## 🧪 Testing
- Usabilidad: Navegación intuitiva
- Responsividad: Compatible móvil/desktop
- Reglas de negocio: Cálculos verificados
- Compatibilidad: Navegadores modernos

## 📞 Contacto
Desarrollado para prueba técnica de desempeño comercial.

---
**Desarrollado con ❤️ usando PHP, Bootstrap y Chart.js**
