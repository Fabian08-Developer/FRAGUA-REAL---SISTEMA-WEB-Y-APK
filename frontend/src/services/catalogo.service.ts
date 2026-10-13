// M04 — Menú y Catálogo. Espeja app/Http/Controllers/Api/CatalogoController.php:
// GET /categorias y GET /productos son públicas y ya filtran activo+disponible según el
// rol autenticado (RN-M04-08) — el frontend no repite ese filtro, solo lo consume. Las
// mutaciones (crear/editar categoría o producto, alternar disponibilidad) son exclusivas
// Administrador (toggleDisponible también la usa Mesero), igual que en el backend.
import api from './api'
import type { Categoria, Producto, Combo, Variante, Extra } from '@/types/models'

export interface CrearCategoriaPayload {
  nombre: string
  activo?: boolean
}

export interface CrearProductoPayload {
  categoria_id: number
  nombre: string
  descripcion?: string | null
  precio_base: number
  tipo_venta: 'bebida' | 'alimento' | 'otro'
  disponible?: boolean
  imagen_url?: string | null
  activo?: boolean
  // Fase 2, tarea 3 (RF-M04-011): ids de los extras generales que ofrece el producto.
  extras_generales?: number[]
}

export interface CrearVariantePayload {
  producto_id: number
  nombre: string
  precio: number
  activa?: boolean
}

export interface CrearExtraPayload {
  nombre: string
  precio: number
  alcance: 'general' | 'especifico'
  producto_id?: number | null
  activo?: boolean
}

export interface CrearComboPayload {
  nombre: string
  precio: number
  tipo_venta: 'bebida' | 'alimento' | 'otro'
  vigencia_inicio?: string | null
  vigencia_fin?: string | null
  franja_inicio?: string | null
  franja_fin?: string | null
  dias_semana?: number[] | null
  activo?: boolean
  productos: { producto_id: number; cantidad: number }[]
}

export const catalogoService = {
  categorias() {
    return api.get<Categoria[]>('/categorias')
  },
  // Fase 1, tarea 5 (RF-M04-025): q busca por nombre, categoria_id filtra. El público solo recibe
  // lo que se puede pedir; el personal recibe también lo agotado, para poder reactivarlo.
  productos(params?: { q?: string; categoria_id?: number }) {
    return api.get<Producto[]>('/productos', { params })
  },
  combos() {
    return api.get<Combo[]>('/combos')
  },
  crearCategoria(payload: CrearCategoriaPayload) {
    return api.post<Categoria>('/categorias', payload)
  },
  actualizarCategoria(id: number, payload: CrearCategoriaPayload) {
    return api.put<Categoria>(`/categorias/${id}`, payload)
  },
  crearProducto(payload: CrearProductoPayload) {
    return api.post<Producto>('/productos', payload)
  },
  actualizarProducto(id: number, payload: CrearProductoPayload) {
    return api.put<Producto>(`/productos/${id}`, payload)
  },
  toggleDisponible(id: number, disponible: boolean) {
    return api.post<Producto>(`/productos/${id}/disponibilidad`, { disponible })
  },
  // RF-M04-016/017 (Fase 1): agotar o reactivar un tamaño/variante. Administrador y Mesero.
  toggleDisponibleVariante(id: number, disponible: boolean) {
    return api.post<Variante>(`/variantes/${id}/disponibilidad`, { disponible })
  },

  // ---- Variantes (M04 parte 2) — siempre en el contexto de su producto ----
  crearVariante(payload: CrearVariantePayload) {
    return api.post<Variante>('/variantes', payload)
  },
  actualizarVariante(id: number, payload: CrearVariantePayload) {
    return api.put<Variante>(`/variantes/${id}`, payload)
  },

  // ---- Extras (M04 parte 2) — "general" aplica a todo el menú, "especifico" a un producto ----
  extras() {
    return api.get<Extra[]>('/extras')
  },
  crearExtra(payload: CrearExtraPayload) {
    return api.post<Extra>('/extras', payload)
  },
  actualizarExtra(id: number, payload: CrearExtraPayload) {
    return api.put<Extra>(`/extras/${id}`, payload)
  },

  // ---- Combos: gestión completa (M04 parte 2) ----
  // combosAdmin trae TODOS los combos (incluidos inactivos/vencidos); combos() de arriba
  // es la lista pública, ya filtrada a lo disponible hoy.
  combosAdmin() {
    return api.get<Combo[]>('/combos-admin')
  },
  crearCombo(payload: CrearComboPayload) {
    return api.post<Combo>('/combos', payload)
  },
  actualizarCombo(id: number, payload: CrearComboPayload) {
    return api.put<Combo>(`/combos/${id}`, payload)
  },
}
