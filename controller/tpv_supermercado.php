<?php

/*
 * This file is part of supermercado_solidario
 * Copyright (C) 2014-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_model('agente.php');
require_model('albaran_cliente.php');
require_model('articulo.php');
require_model('caja.php');
require_model('clan_familiar.php');
require_model('cliente.php');
require_model('divisa.php');
require_model('ejercicio.php');
require_model('pais.php');
require_model('serie.php');
require_model('terminal_caja.php');

class tpv_supermercado extends fs_controller
{
   public $agente;
   public $articulo;
   public $busqueda;
   public $caja;
   public $clan;
   public $cliente;
   public $cliente_url;
   public $numlineas;
   public $resultado;
   public $terminal;
   
   public function __construct()
   {
      parent::__construct('tpv_supermercado', 'Supermercado', 'TPV');
   }
   
   protected function private_core()
   {
      $this->share_extensions();
      
      $this->agente = $this->user->get_agente();
      $this->busqueda = '';
      $this->clan = new clan_familiar();
      $this->cliente = new cliente();
      $this->numlineas = 0;
      $this->resultado = array();
      
      if( isset($_POST['buscar_cliente']) )
      {
         $this->buscar_cliente();
      }
      else if( isset($_POST['buscar_articulo']) )
      {
         $this->buscar_articulo(TRUE);
      }
      else if( isset($_POST['codbar2']) )
      {
         $this->buscar_articulo();
      }
      else if($this->agente)
      {
         $this->caja = FALSE;
         $this->terminal = FALSE;
         $caja = new caja();
         $terminal0 = new terminal_caja();
         
         if($this->user->admin)
         {
            $cajas = $caja->all();
         }
         else
         {
            $cajas = $caja->all_by_agente($this->agente->codagente);
         }
         
         foreach($cajas as $cj)
         {
            if( $cj->abierta() )
            {
               $this->caja = $cj;
               $this->terminal = $terminal0->get($cj->fs_id);
               break;
            }
         }
         
         if(!$this->caja)
         {
            if( isset($_POST['terminal']) )
            {
               $this->terminal = $terminal0->get($_POST['terminal']);
               if(!$this->terminal)
               {
                  $this->new_error_msg('Terminal no encontrado.');
               }
               else if( $this->terminal->disponible() )
               {
                  $this->caja = new caja();
                  $this->caja->fs_id = $this->terminal->id;
                  $this->caja->codagente = $this->agente->codagente;
                  $this->caja->dinero_inicial = floatval($_POST['d_inicial']);
                  $this->caja->dinero_fin = floatval($_POST['d_inicial']);
                  if( $this->caja->save() )
                  {
                     $this->new_message("Caja iniciada con ".$this->show_precio($this->caja->dinero_inicial) );
                  }
                  else
                     $this->new_error_msg("¡Imposible guardar los datos de caja!");
               }
               else
                  $this->new_error_msg('El terminal ya no está disponible.');
            }
            else if( isset($_GET['terminal']) )
            {
               $this->terminal = $terminal0->get($_GET['terminal']);
               if($this->terminal)
               {
                  $this->terminal->abrir_cajon();
                  $this->terminal->save();
               }
               else
                  $this->new_error_msg('Terminal no encontrado.');
            }
         }
         
         if($this->caja)
         {
            if($this->caja->codagente == $this->user->codagente OR $this->user->admin)
            {
               $this->caja_iniciada();
            }
            else
            {
               $this->new_error_msg("Esta caja está bloqueada por el agente ".$this->caja->agente->get_fullname().
                       ". Puedes cerrarla desde Contabilidad &gt; Caja.");
            }
         }
         else
         {
            $this->resultado = $terminal0->disponibles();
         }
      }
      else
      {
         $this->new_error_msg('No tienes un <a href="'.$this->user->url().'">agente asociado</a>
            a tu usuario, y por tanto no puedes hacer tickets.');
      }
   }
   
   public function url()
   {
      if( isset($this->cliente_url) )
      {
         return $this->cliente_url;
      }
      else
      {
         return parent::url();
      }
   }
   
   private function caja_iniciada()
   {
      $this->template = 'tpv_supermercado2';
      
      if( isset($_GET['cerrando']) )
      {
         $this->template = 'tpv_supermercado_cierre';
         if($this->terminal)
         {
            $this->terminal->abrir_cajon();
            $this->terminal->save();
         }
      }
      else if( isset($_POST['cerrar_caja']) )
      {
         $this->cerrar_caja();
      }
      else if( isset($_GET['delete']) )
      {
         $this->borrar_ticket();
      }
      else if( isset($_GET['cliente']) )
      {
         $this->seleccionar_cliente($_GET['cliente']);
      }
      else if( isset($_POST['cliente']) )
      {
         $this->seleccionar_cliente($_POST['cliente']);
      }
   }
   
   private function cerrar_caja()
   {
      $dinero_contado = 0;
      $dinero_contado += intval($_POST['m1c'])*0.01;
      $dinero_contado += intval($_POST['m2c'])*0.02;
      $dinero_contado += intval($_POST['m5c'])*0.05;
      $dinero_contado += intval($_POST['m10c'])*0.1;
      $dinero_contado += intval($_POST['m20c'])*0.2;
      $dinero_contado += intval($_POST['m50c'])*0.5;
      $dinero_contado += intval($_POST['m1e']);
      $dinero_contado += intval($_POST['m2e'])*2;
      $dinero_contado += intval($_POST['b5e'])*5;
      $dinero_contado += intval($_POST['b10e'])*10;
      $dinero_contado += intval($_POST['b20e'])*20;
      $dinero_contado += intval($_POST['b50e'])*50;
      $dinero_contado += intval($_POST['b100e'])*100;
      $dinero_contado += intval($_POST['b200e'])*200;
      $dinero_contado += intval($_POST['b500e'])*500;
      
      $this->caja->fecha_fin = Date('d-m-Y H:i:s');
      if( $this->caja->save() )
      {
         if($this->terminal)
         {
            /// imprimimos dos veces
            for($i = 0; $i < 2; $i++)
            {
               $this->terminal->cortar_papel();
               $this->terminal->add_linea_big("CIERRE DE CAJA:");
               $this->terminal->add_linea("\n\nAgente: ".$this->agente->nombre."\n");
               $this->terminal->add_linea("Caja: ".$this->caja->fs_id."\n");
               $this->terminal->add_linea("Fecha inicial: ".$this->caja->fecha_inicial."\n");
               $this->terminal->add_linea("Cambio inicial: ".$this->show_precio($this->caja->dinero_inicial, FALSE, FALSE)."\n");
               $this->terminal->add_linea("Fecha fin: ".$this->caja->show_fecha_fin()."\n");
               $this->terminal->add_linea("Ingresos estimados: ".$this->show_precio($this->caja->diferencia(), FALSE, FALSE)."\n");
               $this->terminal->add_linea("Ingresos contado: ".$this->show_precio($dinero_contado, FALSE, FALSE)."\n");
               $this->terminal->add_linea("Diferencia: ".$this->show_precio($dinero_contado-$this->caja->diferencia(), FALSE, FALSE)."\n");
               $this->terminal->add_linea("Tickets: ".$this->caja->tickets."\n\n");
               $this->terminal->add_linea("Observaciones:\n\n\n\n");
               $this->terminal->add_linea("Firma:\n\n\n\n\n\n\n\n\n\n\n");
               $this->terminal->cortar_papel();
               $this->terminal->save();
            }
         }
         
         /// recargamos la página
         header('location: '.$this->url());
      }
      else
         $this->new_error_msg("¡Imposible cerrar la caja!");
   }
   
   private function buscar_cliente()
   {
      $this->template = 'ajax_buscar_cliente';
      $this->busqueda = trim($_POST['buscar_cliente']);
      $this->resultado = $this->cliente->search_by_dni($this->busqueda);
   }
   
   private function seleccionar_cliente($codcliente)
   {
      $this->cliente = $this->cliente->get($codcliente);
      $this->cliente_url = 'index.php?page=tpv_supermercado&cliente='.$codcliente;
      
      $cliente2clan = new cliente2clan();
      $this->clan = $cliente2clan->get_clan($codcliente);
      if(!$this->clan)
      {
         $this->template = 'tpv_supermercado_no_clan';
         $this->new_message('Este cliente no está en ningún clan familiar. Avisa a administración.');
      }
      else if($this->clan->restringido AND !$this->user->admin)
      {
         $this->template = 'tpv_supermercado_no_clan';
         $this->new_message('Sólo un administrador puede hacer un ticket a este cliente.');
      }
      else
      {
         $this->template = 'tpv_supermercado3';
         if( isset($_POST['numlineas']) )
         {
            $this->guardar_ticket();
            $this->cliente_url = 'index.php?page=tpv_supermercado';
            $this->template = 'tpv_supermercado2';
         }
      }
   }
   
   private function buscar_articulo($full_data = FALSE)
   {
      if($full_data)
      {
         $this->template = 'ajax_buscar_articulo2';
      }
      else
         $this->template = 'ajax_buscar_articulo';
      
      if( isset($_POST['codbar2']) )
      {
         $codbar = $_POST['codbar2'];
      }
      else
         $codbar = $_POST['buscar_articulo'];
      
      $articulo = new articulo();
      foreach($articulo->search_by_codbar($codbar) as $a)
      {
         $this->articulo = $a;
         break;
      }
      
      if( isset($_POST['numlineas']) )
      {
         $this->numlineas = $_POST['numlineas'];
      }
      else
         $this->numlineas = 0;
   }
   
   private function guardar_ticket()
   {
      $continuar = TRUE;
      
      $ejercicio = new ejercicio();
      $ejercicio = $ejercicio->get_by_fecha( $this->today() );
      if(!$ejercicio)
      {
         $this->new_error_msg('Ejercicio no encontrado.');
         $continuar = FALSE;
      }
      
      $divisa = new divisa();
      $divisa = $divisa->get($this->empresa->coddivisa);
      
      $albaran = new albaran_cliente();
      
      if( $this->duplicated_petition($_POST['petition_id']) )
      {
         $this->new_error_msg('Petición duplicada. Has hecho doble clic sobre el botón Guardar
               y se han enviado dos peticiones. Mira en <a href="'.$albaran->url().'">'.FS_ALBARANES.'</a>
               para ver si el '.FS_ALBARAN.' se ha guardado correctamente.');
         $continuar = FALSE;
      }
      
      if( isset($_POST['total2']) )
      {
         $total = floatval($_POST['total2']);
         if( $this->clan->limite - $total - $this->clan->gastado() < 0 )
         {
            $continuar = FALSE;
            $this->new_error_msg('El cliente ha superado el límite de gasto.');
         }
      }
      else
      {
         $continuar = FALSE;
         $this->new_error_msg('Falta el total del '.FS_ALBARAN);
      }
         
      
      if($continuar)
      {
         $albaran->codalmacen = $this->empresa->codalmacen;
         $albaran->codejercicio = $ejercicio->codejercicio;
         $albaran->codserie = $this->empresa->codserie;
         $albaran->codpago = $this->empresa->codpago;
         $albaran->coddivisa = $divisa->coddivisa;
         $albaran->tasaconv = $divisa->tasaconv;
         $albaran->codagente = $this->agente->codagente;
         $albaran->observaciones = $_POST['observaciones'];
         
         foreach($this->cliente->get_direcciones() as $d)
         {
            if($d->domfacturacion)
            {
               $albaran->codcliente = $this->cliente->codcliente;
               $albaran->cifnif = $this->cliente->cifnif;
               $albaran->nombrecliente = $this->cliente->razonsocial;
               $albaran->apartado = $d->apartado;
               $albaran->ciudad = $d->ciudad;
               $albaran->coddir = $d->id;
               $albaran->codpais = $d->codpais;
               $albaran->codpostal = $d->codpostal;
               $albaran->direccion = $d->direccion;
               $albaran->provincia = $d->provincia;
               break;
            }
         }
         
         if( is_null($albaran->codcliente) )
         {
            $this->new_error_msg("No hay ninguna dirección asociada al cliente.");
         }
         else if( $albaran->save() )
         {
            $articulo = new articulo();
            
            $n = floatval($_POST['numlineas']);
            for($i = 0; $i < $n; $i++)
            {
               if( isset($_POST['referencia_'.$i]) )
               {
                  $art0 = $articulo->get($_POST['referencia_'.$i]);
                  if($art0)
                  {
                     $linea = new linea_albaran_cliente();
                     $linea->idalbaran = $albaran->idalbaran;
                     $linea->referencia = $art0->referencia;
                     $linea->descripcion = $art0->descripcion;
                     $linea->codimpuesto = $art0->codimpuesto;
                     $linea->iva = floatval($_POST['iva_'.$i]);
                     $linea->pvpunitario = floatval($_POST['pvp_'.$i]);
                     $linea->cantidad = floatval($_POST['cantidad_'.$i]);
                     $linea->pvpsindto = ($linea->pvpunitario * $linea->cantidad);
                     $linea->pvptotal = ($linea->pvpunitario * $linea->cantidad);
                     
                     if( $linea->save() )
                     {
                        /// descontamos del stock
                        $art0->sum_stock($albaran->codalmacen, 0 - $linea->cantidad);
                        
                        $albaran->neto += $linea->pvptotal;
                        $albaran->totaliva += ($linea->pvptotal * $linea->iva/100);
                     }
                     else
                     {
                        $this->new_error_msg("¡Imposible guardar la línea con referencia: ".$linea->referencia);
                        $continuar = FALSE;
                     }
                  }
                  else
                  {
                     $this->new_error_msg("Artículo no encontrado: ".$_POST['referencia_'.$i]);
                     $continuar = FALSE;
                  }
               }
            }
            
            if($continuar)
            {
               /// redondeamos
               $albaran->neto = round($albaran->neto, 2);
               $albaran->totaliva = round($albaran->totaliva, 2);
               $albaran->total = $albaran->neto + $albaran->totaliva;
               
               if( $albaran->save() )
               {
                  $this->new_message("<a href='".$albaran->url()."'>".FS_ALBARAN."</a> guardado correctamente.");
                  
                  $this->imprimir_ticket($albaran);
                  
                  /// actualizamos la caja
                  $this->caja->dinero_fin += $albaran->total;
                  $this->caja->tickets += 1;
                  $this->caja->ip = $_SERVER['REMOTE_ADDR'];
                  if( !$this->caja->save() )
                  {
                     $this->new_error_msg("¡Imposible actualizar la caja!");
                  }
               }
               else
                  $this->new_error_msg("¡Imposible actualizar el ".FS_ALBARAN."!");
            }
            else if( $albaran->delete() )
            {
               $this->new_message(FS_ALBARAN." eliminado correctamente.");
            }
            else
               $this->new_error_msg("¡Imposible eliminar el ".FS_ALBARAN."!");
         }
         else
            $this->new_error_msg("¡Imposible guardar el ".FS_ALBARAN."!");
      }
   }
   
   private function imprimir_ticket($factura, $cajon = TRUE)
   {
      if($this->terminal)
      {
         if($cajon)
         {
            $this->terminal->abrir_cajon();
         }
         
         $num = 0;
         while($num < $this->terminal->num_tickets)
         {
            $medio = $this->terminal->anchopapel / 2.5;
            $this->terminal->add_linea_big( $this->terminal->center_text($this->empresa->nombre, $medio)."\n");
            
            if($this->empresa->lema != '')
            {
               $this->terminal->add_linea( $this->terminal->center_text($this->empresa->lema) . "\n\n");
            }
            else
               $this->terminal->add_linea("\n");
            
            $this->terminal->add_linea( $this->terminal->center_text($this->empresa->direccion . " - " . $this->empresa->ciudad) . "\n");
            $this->terminal->add_linea( $this->terminal->center_text("CIF: " . $this->empresa->cifnif));
            $this->terminal->add_linea("\n\n");
            
            if($this->empresa->horario != '')
            {
               $this->terminal->add_linea( $this->terminal->center_text($this->empresa->horario) . "\n\n");
            }
            
            $linea = "\n".ucfirst(FS_FACTURA_SIMPLIFICADA).": " . $factura->codigo . "\n";
            $linea .= $factura->fecha. " " . Date('H:i', strtotime($factura->hora)) . "\n";
            $this->terminal->add_linea($linea);
            $this->terminal->add_linea("Cliente: " . $factura->nombrecliente . "\n");
            $this->terminal->add_linea("Empleado: " . $factura->codagente . "\n\n");
            
            $width = $this->terminal->anchopapel - 15;
            $this->terminal->add_linea(
                    sprintf("%3s", "Ud.")." ".
                    sprintf("%-".$width."s", "Articulo")." ".
                    sprintf("%10s", "TOTAL")."\n"
            );
            $this->terminal->add_linea(
                    sprintf("%3s", "---")." ".
                    sprintf("%-".$width."s", substr("--------------------------------------------------------", 0, $width-1))." ".
                    sprintf("%10s", "----------")."\n"
            );
            foreach($factura->get_lineas() as $col)
            {
               $linea = sprintf("%3s", $col->cantidad)." ".sprintf("%-".$width."s", substr($col->descripcion, 0, $width-1))." "
                       .sprintf("%10s", $this->show_numero($col->total_iva()))."\n";
               
               $this->terminal->add_linea($linea);
            }
            
            $linea = '';
            for($i = 0; $i < $this->terminal->anchopapel; $i++)
            {
               $linea .= '-';
            }
            
            $linea .= "\n".$this->terminal->center_text(
                    FS_IVA.": ".$this->show_precio($factura->totaliva, $factura->coddivisa, FALSE).'   '.
                    "Total: ".$this->show_precio($factura->total, $factura->coddivisa, FALSE)
            );
            
            if( isset($_POST['efectivo']) AND isset($_POST['cambio']) )
            {
               $this->terminal->add_linea(
                       $this->terminal->center_text(
                               "Entregado: ".$this->show_precio($_POST['efectivo'], $factura->coddivisa, FALSE).
                               "  Cambio: ".$this->show_precio($_POST['cambio'], $factura->coddivisa, FALSE),
                               42
                        )."\n"
               );
            }
            
            $linea .= "\n\n".$this->terminal->center_text(
                    'Pendiente: '.$this->show_precio($this->clan->pendiente(), $factura->coddivisa, FALSE)
            )."\n\n\n\n\n\n\n\n";
            $this->terminal->add_linea($linea);
            $this->terminal->cortar_papel();
            $num++;
         }
         
         $this->terminal->save();
      }
   }
   
   private function borrar_ticket()
   {
      $albaran = new albaran_cliente();
      $alb = $albaran->get_by_codigo($_GET['delete']);
      if($alb)
      {
         if($alb->ptefactura)
         {
            /// imprimimos
            if($this->terminal)
            {
               $this->terminal->cortar_papel();
               $this->terminal->add_linea("----------------------------------------\n");
               $this->terminal->add_linea( $fpt->center_text('*** TICKET BORRADO ***', 42)."\n" );
               $this->terminal->add_linea("----------------------------------------\n");
               $this->terminal->save();
               
               $this->imprimir_ticket($alb);
               
               /// actualizamos el stock
               $articulo = new articulo();
               foreach($alb->get_lineas() as $linea)
               {
                  $art0 = $articulo->get($linea->referencia);
                  if($art0)
                  {
                     $art0->sum_stock($alb->codalmacen, $linea->cantidad);
                     $art0->save();
                  }
               }
               
               if( $alb->delete() )
               {
                  $this->new_message("Ticket ".$_GET['delete']." borrado correctamente.");
                  
                  /// actualizamos la caja
                  $this->caja->dinero_fin -= $alb->total;
                  $this->caja->tickets -= 1;
                  if( !$this->caja->save() )
                  {
                     $this->new_error_msg("¡Imposible actualizar la caja!");
                  }
               }
               else
                  $this->new_error_msg("¡Imposible borrar el ticket ".$_GET['delete']."!");
            }
         }
         else
            $this->new_error_msg('No se ha podido borrar este '.FS_ALBARAN.' porque ya está facturado.');
      }
      else
         $this->new_error_msg("Ticket no encontrado.");
   }
   
   public function progressbar()
   {
      return intval($this->clan->gastado()/$this->clan->limite*100);
   }
   
   private function share_extensions()
   {
      $fsext = new fs_extension();
      $fsext->name = 'api_remote_printer';
      $fsext->from = __CLASS__;
      $fsext->type = 'api';
      $fsext->text = 'remote_printer';
      $fsext->save();
   }
}
